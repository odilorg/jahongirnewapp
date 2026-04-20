<?php

declare(strict_types=1);

namespace App\Services\Wacli;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Thin SSH wrapper around vps-main's wacli-read-for-jahongir.sh. Single file
 * that touches the wire — IngestWhatsAppAsLead and the command stay
 * library-agnostic and trivially mockable in tests.
 *
 * Remote auth is key-based, restricted to the fixed wrapper via authorized_keys
 * `command="…"`. This client does not and cannot run arbitrary remote commands.
 */
class WacliRemoteClient
{
    public function __construct(private readonly array $config)
    {
    }

    public static function fromConfig(): self
    {
        return new self(config('wacli.remote') + ['_timeout' => config('wacli.remote.ssh_timeout', 30)]);
    }

    /**
     * @return Collection<int, InboundWhatsAppMessage>
     */
    public function fetchMessages(): Collection
    {
        $process = new Process([
            'ssh',
            '-i', $this->config['identity'],
            '-p', (string) $this->config['port'],
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=10',
            sprintf('%s@%s', $this->config['user'], $this->config['host']),
        ]);
        $process->setTimeout((int) ($this->config['_timeout'] ?? 30));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'wacli SSH read failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $raw = $process->getOutput();
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $messages = $payload['data']['messages'] ?? [];

        return collect($messages)
            ->map(fn (array $m) => $this->normalize($m))
            ->filter()
            ->values();
    }

    private function normalize(array $m): ?InboundWhatsAppMessage
    {
        $msgId = (string) ($m['MsgID'] ?? '');
        $chatJid = (string) ($m['ChatJID'] ?? '');

        if ($msgId === '' || $chatJid === '') {
            return null;
        }

        $ts = $m['Timestamp'] ?? null;
        $sentAt = null;
        if (is_string($ts) && $ts !== '') {
            try {
                $sentAt = Carbon::parse($ts);
            } catch (\Throwable) {
                $sentAt = null;
            }
        }

        return new InboundWhatsAppMessage(
            remoteMessageId: $chatJid.':'.$msgId,
            msgId: $msgId,
            chatJid: $chatJid,
            senderJid: ! empty($m['SenderJID']) ? (string) $m['SenderJID'] : null,
            chatName: ! empty($m['ChatName']) ? (string) $m['ChatName'] : null,
            body: (string) ($m['Text'] ?? $m['DisplayText'] ?? ''),
            isFromMe: (bool) ($m['FromMe'] ?? false),
            mediaType: ! empty($m['MediaType']) ? (string) $m['MediaType'] : null,
            sentAt: $sentAt,
        );
    }
}
