<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GygFetchEmails extends Command
{
    protected $signature = 'gyg:fetch-emails
        {--limit=50 : Maximum number of emails to fetch per run}
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

        // Step 3: Persist each email idempotently
        $stats = ['new' => 0, 'duplicate' => 0, 'error' => 0];

        foreach ($gygEnvelopes as $envelope) {
            // Fetch Message-ID header + body in one call (mailbox-safe via --preview)
            $fetched = $this->fetchMessageWithId($envelope['id']);
            $messageId = $fetched['message_id'] ?? $this->syntheticMessageId($envelope);

            if (! $messageId) {
                $this->warn("[gyg:fetch-emails] Skipping email without any usable ID: " . ($envelope['subject'] ?? 'no subject'));
                Log::warning('gyg:fetch-emails: no usable message ID', ['subject' => $envelope['subject'] ?? null]);
                $stats['error']++;
                continue;
            }

            // Idempotency: skip if already stored
            if (GygInboundEmail::where('email_message_id', $messageId)->exists()) {
                $this->line("  ⏭ Already stored: " . $this->truncate($envelope['subject'] ?? '', 60));
                $stats['duplicate']++;
                continue;
            }

            if ($dryRun) {
                $this->line("  🆕 [DRY-RUN] Would store: " . $this->truncate($envelope['subject'] ?? '', 60));
                $stats['new']++;
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
                    'processing_status' => 'fetched',
                ]);

                $this->line("  ✅ Stored: " . $this->truncate($envelope['subject'] ?? '', 60));
                Log::info('gyg:fetch-emails: email stored', [
                    'message_id' => $messageId,
                    'subject'    => $this->truncate($envelope['subject'] ?? '', 100),
                ]);
                $stats['new']++;
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

        $summary = "gyg:fetch-emails: done — new={$stats['new']}, duplicate={$stats['duplicate']}, error={$stats['error']}";
        $this->info("[gyg:fetch-emails] $summary");
        Log::info($summary);

        return $stats['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Himalaya integration ────────────────────────────

    private function fetchEnvelopes(int $limit): ?array
    {
        $result = Process::timeout(30)->run([
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
     * @return array{message_id: ?string, body: ?string}
     */
    private function fetchMessageWithId(string $envelopeId): array
    {
        $result = Process::timeout(15)->run([
            self::HIMALAYA_BIN, 'message', 'read',
            '--preview',
            '--header', 'Message-ID',
            $envelopeId,
        ]);

        if (! $result->successful()) {
            Log::warning('gyg:fetch-emails: message read failed', [
                'id'     => $envelopeId,
                'stderr' => $result->errorOutput(),
            ]);
            return ['message_id' => null, 'body' => null];
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

        return ['message_id' => $messageId, 'body' => $body ?: null];
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
     * Prefixed with 'gmail-uid:' to distinguish from real Message-IDs.
     * Should be extremely rare — GYG uses SendGrid which always sets Message-ID.
     */
    private function syntheticMessageId(array $envelope): ?string
    {
        $uid     = $envelope['id'] ?? '';
        $subject = $envelope['subject'] ?? '';
        $date    = $envelope['date'] ?? '';

        if (empty($uid)) {
            return null;
        }

        return 'gmail-uid:' . $uid . ':' . md5($subject . $date);
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
