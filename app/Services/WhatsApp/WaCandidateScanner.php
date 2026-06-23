<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Symfony\Component\Process\Process;

/**
 * The ONLY place we reach the wacli host for lead detection. Read-only: SSHes
 * with a command-locked key that can ONLY run the read-only candidate scanner on
 * vps-main (no shell, no send, no mailbox/store mutation). Returns the parsed
 * candidate list. Never writes anything here.
 *
 * @phpstan-type Candidate array{phone: string, first_inbound: string, last_inbound_at: ?string, inbound: int, outbound: int}
 */
class WaCandidateScanner
{
    /** @return array{as_of: ?string, candidates: array<int, array<string, mixed>>} */
    public function scan(int $days, int $max): array
    {
        $ssh = (array) config('wa_leads.ssh');

        $process = new Process([
            'ssh',
            '-i', (string) $ssh['key'],
            '-p', (string) $ssh['port'],
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . (string) $ssh['known_hosts'],
            "{$ssh['user']}@{$ssh['host']}",
            "python3 {$ssh['remote_script']} --days {$days} --max {$max}",
        ]);
        $process->setTimeout((int) $ssh['timeout']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('wa candidate scan failed: ' . trim($process->getErrorOutput()));
        }

        $json = json_decode($process->getOutput(), true);
        if (! is_array($json) || ! isset($json['candidates']) || ! is_array($json['candidates'])) {
            throw new \RuntimeException('wa candidate scan: unparseable output');
        }

        return ['as_of' => $json['as_of'] ?? null, 'candidates' => $json['candidates']];
    }
}
