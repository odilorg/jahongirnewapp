<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GygFetchEmails extends Command
{
    protected $signature = 'gyg:fetch-emails
        {--limit=200 : Maximum number of emails to fetch per run}
        {--dry-run : Fetch and display but do not persist}';

    protected $description = 'Fetch GYG booking emails from Gmail and persist for processing';

    private const GYG_SENDER_PATTERNS = [
        'getyourguide.com',
        'reply.getyourguide.com',
        'notification.getyourguide.com',
    ];

    private const HIMALAYA_BIN = 'himalaya';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('[gyg:fetch-emails] Starting email fetch...');
        Log::info('gyg:fetch-emails: starting', ['limit' => $limit, 'dry_run' => $dryRun]);

        // Step 1: Fetch envelope list from Gmail
        $envelopes = $this->fetchEnvelopes($limit);
        if ($envelopes === null) {
            $this->error('[gyg:fetch-emails] Failed to fetch envelopes from Gmail');
            Log::error('gyg:fetch-emails: envelope fetch failed');
            return self::FAILURE;
        }

        $this->info("[gyg:fetch-emails] Fetched " . count($envelopes) . " envelopes");

        // Step 2: Filter to GYG-related emails only
        $gygEnvelopes = $this->filterGygEmails($envelopes);
        $this->info("[gyg:fetch-emails] Found " . count($gygEnvelopes) . " GYG-related emails");

        if (empty($gygEnvelopes)) {
            Log::info('gyg:fetch-emails: no new GYG emails found');
            return self::SUCCESS;
        }

        // Step 3: Preload known message IDs for in-memory dedupe (single query)
        $knownIds = GygInboundEmail::pluck('email_message_id')->flip();

        // Step 4: Persist each email idempotently
        $stats = ['new' => 0, 'duplicate' => 0, 'error' => 0, 'timeout' => 0];

        foreach ($gygEnvelopes as $envelope) {
            // Fetch Message-ID header + body in one call (mailbox-safe via --preview)
            $fetched = $this->fetchMessageWithId($envelope['id'], $envelope);
            $timedOut = $fetched['timed_out'] ?? false;
            $messageId = $fetched['message_id'] ?? $this->syntheticMessageId($envelope, $fetched['body']);

            if (! $messageId) {
                $this->warn("[gyg:fetch-emails] Skipping email without any usable ID: " . ($envelope['subject'] ?? 'no subject'));
                Log::warning('gyg:fetch-emails: no usable message ID', ['subject' => $envelope['subject'] ?? null]);
                $timedOut ? $stats['timeout']++ : $stats['error']++;
                continue;
            }

            // Idempotency: skip if already stored (in-memory check, no DB query)
            if ($knownIds->has($messageId)) {
                $this->line("  ⏭ Already stored: " . $this->truncate($envelope['subject'] ?? '', 60));
                $stats['duplicate']++;
                continue;
            }

            if ($dryRun) {
                $label = $timedOut ? '[DRY-RUN, TIMEOUT]' : '[DRY-RUN]';
                $this->line("  🆕 {$label} Would store: " . $this->truncate($envelope['subject'] ?? '', 60));
                $timedOut ? $stats['timeout']++ : $stats['new']++;
                continue;
            }

            $body = $fetched['body'];

            try {
                GygInboundEmail::create([
                    'email_message_id'  => $messageId,
                    'email_from'        => $this->extractAddress($envelope['from'] ?? []),
                    'email_to'          => $this->extractAddress($envelope['to'] ?? []),
                    'email_subject'     => $this->truncate($envelope['subject'] ?? '', 1000),
                    'email_date'        => $this->parseDate($envelope['date'] ?? null),
                    'body_text'         => $body,
                    'body_html'         => null, // himalaya returns plain text; HTML deferred
                    // Timed-out emails are stored as 'skipped' so process-emails doesn't
                    // waste cycles on them and repeated runs recognise them as duplicates.
                    'processing_status' => $timedOut ? 'skipped' : 'fetched',
                ]);

                $knownIds[$messageId] = true; // track within this run
                $icon = $timedOut ? '⏱' : '✅';
                $this->line("  {$icon} Stored: " . $this->truncate($envelope['subject'] ?? '', 60));
                Log::info('gyg:fetch-emails: email stored', [
                    'message_id'   => $messageId,
                    'subject'      => $this->truncate($envelope['subject'] ?? '', 100),
                    'timed_out'    => $timedOut,
                    'has_body'     => $body !== null,
                ]);
                $timedOut ? $stats['timeout']++ : $stats['new']++;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Race condition: another process stored it between our check and insert
                $this->line("  ⏭ Duplicate (race): " . $this->truncate($envelope['subject'] ?? '', 60));
                $stats['duplicate']++;
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed to store: " . $e->getMessage());
                Log::error('gyg:fetch-emails: store failed', [
                    'message_id' => $messageId,
                    'error'      => $e->getMessage(),
                ]);
                $stats['error']++;
            }
        }

        $summary = "gyg:fetch-emails: done — new={$stats['new']}, duplicate={$stats['duplicate']}, timeout={$stats['timeout']}, error={$stats['error']}";
        $this->info("[gyg:fetch-emails] $summary");
        Log::info($summary);

        return $stats['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Himalaya integration ────────────────────────────

    private function fetchEnvelopes(int $limit): ?array
    {
        // Bumped 30s → 60s 2026-04-18. Real-world IMAP fetches against
        // Gmail regularly take 45-55s depending on Gmail server load;
        // 30s produced frequent FAILED runs leaving emails queued for
        // another 15-min cycle.
        $result = Process::timeout(60)->run([
            self::HIMALAYA_BIN, 'envelope', 'list',
            '--folder', 'INBOX',
            '--page-size', (string) $limit,
            '--output', 'json',
        ]);

        if (! $result->successful()) {
            Log::error('gyg:fetch-emails: himalaya envelope list failed', [
                'stderr' => $result->errorOutput(),
                'code'   => $result->exitCode(),
            ]);
            return null;
        }

        $output = trim($result->output());
        if (empty($output)) {
            return [];
        }

        $parsed = json_decode($output, true);
        if (! is_array($parsed)) {
            Log::error('gyg:fetch-emails: invalid JSON from himalaya', [
                'output' => $this->truncate($output, 200),
            ]);
            return null;
        }

        return $parsed;
    }

    /**
     * Fetch Message-ID header and body in a single himalaya call.
     * Uses --header Message-ID to include the header at the top of the output.
     * Uses --preview to avoid marking the email as read.
     *
     * Returns timed_out=true when Gmail IMAP does not respond in time — the caller
     * stores the email stub as 'skipped' so repeated runs don't keep hammering IMAP.
     *
     * @return array{message_id: ?string, body: ?string, timed_out: bool}
     */
    private function fetchMessageWithId(string $envelopeId, array $envelope = []): array
    {
        try {
            // Bumped 15s → 45s 2026-04-28. Same IMAP latency issue that caused the
            // envelope list to be bumped 30s → 60s on 2026-04-18: large HTML emails
            // from GYG/SendGrid routinely exceed 15s. ProcessTimedOutException was
            // previously uncaught here, crashing the entire scheduled run.
            $result = Process::timeout(45)->run([
                self::HIMALAYA_BIN, 'message', 'read',
                '--preview',
                '--header', 'Message-ID',
                $envelopeId,
            ]);
        } catch (ProcessTimedOutException $e) {
            Log::warning('gyg:fetch-emails: message read timed out — stored as skipped', [
                'id'      => $envelopeId,
                'subject' => $envelope['subject'] ?? 'unknown',
                'sender'  => $this->extractAddress($envelope['from'] ?? []),
            ]);
            return ['message_id' => null, 'body' => null, 'timed_out' => true];
        }

        if (! $result->successful()) {
            Log::warning('gyg:fetch-emails: message read failed', [
                'id'      => $envelopeId,
                'subject' => $envelope['subject'] ?? 'unknown',
                'sender'  => $this->extractAddress($envelope['from'] ?? []),
                'folder'  => 'INBOX',
                'stderr'  => $result->errorOutput(),
            ]);
            return ['message_id' => null, 'body' => null, 'timed_out' => false];
        }

        $output = $result->output();
        if (empty($output)) {
            return ['message_id' => null, 'body' => null];
        }

        // Parse Message-ID from the first line(s) of output.
        // himalaya outputs requested headers first, then the body.
        // Format: "Message-ID: <...>\n\n<body>"
        $messageId = null;
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $output, $matches)) {
            $messageId = trim($matches[1]);
        }

        // Body starts after the header block (first blank line)
        $body = $output;
        $headerEnd = strpos($output, "\n\n");
        if ($headerEnd !== false) {
            $body = substr($output, $headerEnd + 2);
        }

        return ['message_id' => $messageId, 'body' => $body ?: null, 'timed_out' => false];
    }

    // ── Filtering ───────────────────────────────────────

    private function filterGygEmails(array $envelopes): array
    {
        return array_values(array_filter($envelopes, function (array $env) {
            $from = $this->extractAddress($env['from'] ?? []);
            foreach (self::GYG_SENDER_PATTERNS as $pattern) {
                if (str_contains(strtolower($from), $pattern)) {
                    return true;
                }
            }
            return false;
        }));
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Fallback dedupe key when RFC Message-ID header is unavailable.
     * Uses a deterministic content-based hash (not Gmail UID, which can change
     * on UIDVALIDITY reset). Includes body preview to distinguish amendments
     * and cancellations that may share similar subjects.
     *
     * Should be extremely rare — GYG uses SendGrid which always sets Message-ID.
     */
    private function syntheticMessageId(array $envelope, ?string $body = null): ?string
    {
        $sender  = strtolower(trim($this->extractAddress($envelope['from'] ?? [])));
        $subject = strtolower(trim($envelope['subject'] ?? ''));
        $date    = trim($envelope['date'] ?? '');

        if (empty($subject) && empty($date)) {
            return null;
        }

        $preview = $body ? substr(trim($body), 0, 500) : '';

        return 'synthetic:' . hash('sha256', $sender . '|' . $subject . '|' . $date . '|' . $preview);
    }

    private function extractAddress(array|string|null $from): string
    {
        if (is_string($from)) {
            return $from;
        }
        if (is_array($from)) {
            return $from['addr'] ?? $from['address'] ?? $from['name'] ?? '';
        }
        return '';
    }

    private function parseDate(?string $dateStr): ?Carbon
    {
        if (! $dateStr) {
            return null;
        }
        try {
            return Carbon::parse($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
