<?php

declare(strict_types=1);

namespace App\Services\Zoho;

use Illuminate\Support\Collection;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Thin wrapper around webklex/php-imap — the single place the library
 * is referenced so IngestEmailAsLead and the console command stay
 * library-agnostic and trivially mockable in tests.
 */
class ZohoMailInboundClient
{
    private ?\Webklex\PHPIMAP\Client $client = null;

    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('zoho.mail_inbound'));
    }

    /** @return Collection<int, InboundEmail> */
    public function unseenMessages(int $limit): Collection
    {
        $messages = $this->inboxFolder()
            ->query()
            ->unseen()
            ->limit($limit)
            ->get();

        return collect($messages)->map(fn (Message $m) => $this->normalize($m))->values();
    }

    /**
     * After DB persistence succeeds, mark the message Seen and move it to
     * the Processed folder. Creates the folder on first use.
     */
    public function markProcessed(string $uid): void
    {
        $inbox = $this->inboxFolder();
        $message = $inbox->query()->getMessageByUid($uid);

        if ($message === null) {
            return;
        }

        $message->setFlag('Seen');

        $processedName = $this->config['processed_folder'] ?? 'Processed';
        $processed = $this->findOrCreateFolder($processedName);

        $message->move($processed->path);
    }

    private function normalize(Message $m): InboundEmail
    {
        [$senderEmail, $senderName] = $this->extractFrom($m);

        $attachments = $m->getAttachments();
        $filenames = collect($attachments)
            ->map(fn ($a) => $a->getName() ?? '')
            ->filter()
            ->take(10)
            ->values()
            ->all();

        return new InboundEmail(
            messageId: (string) ($m->getMessageId() ?? $m->getHeader()?->get('message-id') ?? $m->getUid()),
            uid: (string) $m->getUid(),
            folder: $this->config['inbox_folder'] ?? 'INBOX',
            senderEmail: $senderEmail,
            senderName: $senderName,
            subject: $this->decodeSubject($m->getSubject()),
            body: (string) ($m->getTextBody() ?: $m->getHtmlBody() ?: ''),
            hasAttachments: $filenames !== [],
            attachmentFilenames: $filenames,
        );
    }

    /**
     * Webklex 6.x returns getFrom() as either a single Address, an Attribute
     * wrapping Address(es), or an iterable of Address — none share a uniform
     * shape. We walk every plausible candidate, and fall back to parsing the
     * raw From header when the object graph yields nothing usable.
     *
     * @return array{0: ?string, 1: ?string}  [lowercased email, personal name]
     */
    private function extractFrom(Message $m): array
    {
        foreach ($this->flattenFromField($m->getFrom()) as $addr) {
            $mail = $this->stringify($addr->mail ?? null);
            if ($mail !== null && $mail !== '') {
                return [
                    strtolower(trim($mail)),
                    $this->stringify($addr->personal ?? null) ?: null,
                ];
            }
        }

        $raw = trim((string) ($m->getHeader()?->get('from') ?? ''));
        if ($raw !== '' && preg_match('/<([^>]+)>/', $raw, $match)) {
            $name = trim(str_replace(['"', "'"], '', explode('<', $raw, 2)[0] ?? '')) ?: null;

            return [strtolower(trim($match[1])), $name];
        }
        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [strtolower($raw), null];
        }

        return [null, null];
    }

    /** @return iterable<object> */
    private function flattenFromField(mixed $from): iterable
    {
        if ($from === null) {
            return [];
        }

        // Webklex Attribute wraps values; ->all() returns an iterable/array of them.
        if (is_object($from) && method_exists($from, 'all')) {
            $all = $from->all();
            if (is_iterable($all)) {
                return $all;
            }
        }

        if (is_iterable($from)) {
            return $from;
        }

        if (is_object($from)) {
            return [$from];
        }

        return [];
    }

    // Webklex returns the Subject header untouched. MIME encoded-word subjects
    // (subjects containing non-ASCII, emoji, em-dashes) arrive like
    // "=?UTF-8?Q?Silk_Road_=E2=80=93_family?=" — decode them before we store
    // anything operators will read.
    private function decodeSubject(mixed $raw): string
    {
        $raw = $this->stringify($raw) ?? '';
        if ($raw === '' || ! str_contains($raw, '=?')) {
            return $raw;
        }

        $decoded = @iconv_mime_decode($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        $decoded = @mb_decode_mimeheader($raw);

        return is_string($decoded) && $decoded !== '' ? $decoded : $raw;
    }

    private function stringify(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_object($v) && method_exists($v, '__toString')) {
            return (string) $v;
        }
        if (is_object($v) && method_exists($v, 'toString')) {
            return (string) $v->toString();
        }

        return null;
    }

    private function client(): \Webklex\PHPIMAP\Client
    {
        if ($this->client === null) {
            $cm = new ClientManager();
            $this->client = $cm->make([
                'host'          => $this->config['host'],
                'port'          => $this->config['port'],
                'encryption'    => $this->config['encryption'],
                'validate_cert' => $this->config['validate_cert'],
                'username'      => $this->config['username'],
                'password'      => $this->config['password'],
                'protocol'      => 'imap',
            ]);
            $this->client->connect();
        }

        return $this->client;
    }

    private function inboxFolder()
    {
        return $this->client()->getFolder($this->config['inbox_folder'] ?? 'INBOX');
    }

    private function findOrCreateFolder(string $name)
    {
        $existing = $this->client()->getFolder($name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->client()->createFolder($name, true);
    }
}
