<?php

declare(strict_types=1);

namespace App\Services\Gmail;

use Symfony\Component\Process\Process;

/**
 * The ONLY place himalaya is shelled out for the Gmail-lead pipeline. Read-only:
 * lists the dedicated lead label and reads messages with `--preview` (which does
 * NOT set the \Seen flag). It never sends/moves/deletes here — mailbox mutation
 * after a successful DB write is a separate Phase-2 method. Mirrors the himalaya
 * usage in ViatorFetchEmails / GygFetchEmails on host 161.
 *
 * himalaya quirk: OPTIONS must precede the variadic [QUERY] positional. We pass
 * no query (the label IS the filter), so all flags are simply listed.
 */
class GmailLeadInboundClient
{
    public function __construct(
        private string $account = 'gmail',
        private string $label = 'CRM-Leads',
    ) {
    }

    /**
     * @return array<int, array{id: string, from_addr: string, from_name: string, subject: string, has_attachment: bool}>
     */
    public function labeledEnvelopes(int $limit): array
    {
        $process = new Process([
            'himalaya', 'envelope', 'list',
            '--account', $this->account,
            '--folder', $this->label,
            '--page-size', (string) $limit,
            '--output', 'json',
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('himalaya envelope list failed: ' . $process->getErrorOutput());
        }

        $json = json_decode($process->getOutput(), true);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json as $env) {
            $from = is_array($env['from'] ?? null) ? $env['from'] : [];
            $out[] = [
                'id'             => (string) ($env['id'] ?? ''),
                'from_addr'      => (string) ($from['addr'] ?? ''),
                'from_name'      => (string) ($from['name'] ?? ''),
                'subject'        => (string) ($env['subject'] ?? ''),
                'has_attachment' => (bool) ($env['has_attachment'] ?? false),
            ];
        }

        return array_values(array_filter($out, fn ($e): bool => $e['id'] !== ''));
    }

    /** Read-only message fetch. `--preview` guarantees \Seen is NOT set. */
    public function readRaw(string $envelopeId): string
    {
        $process = new Process([
            'himalaya', 'message', 'read',
            '--account', $this->account,
            '--preview',
            $envelopeId,
        ]);
        $process->setTimeout(45);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('himalaya message read failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /** @param array{id: string, from_addr: string, from_name: string, subject: string, has_attachment: bool} $env */
    public function toInboundEmail(array $env, string $raw): GmailInboundEmail
    {
        [$headers, $body] = $this->splitHeaders($raw);

        $messageId = null;
        if (preg_match('/^Message-ID:\s*<([^>]+)>/mi', $headers, $m)) {
            $messageId = trim($m[1]);
        }

        return new GmailInboundEmail(
            envelopeId: (string) $env['id'],
            messageId: $messageId,
            senderEmail: (string) $env['from_addr'],
            senderName: (string) $env['from_name'],
            subject: (string) $env['subject'],
            body: $body,
            hasAttachments: (bool) $env['has_attachment'],
        );
    }

    /** @return array{0: string, 1: string} [headers, body] */
    private function splitHeaders(string $raw): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        if (is_array($parts) && count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        return ['', $raw];
    }
}
