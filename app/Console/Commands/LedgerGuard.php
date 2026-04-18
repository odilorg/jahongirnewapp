<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\LedgerDisciplineScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * L-017 — CI guard against ledger-discipline regressions.
 *
 *   php artisan ledger:guard                # default severities
 *   php artisan ledger:guard --strict       # promote all warns to strict
 *   php artisan ledger:guard --warn-only    # demote all stricts to warn
 *   php artisan ledger:guard --json         # machine-readable JSON output
 *
 * Exit codes (CI-friendly):
 *   0  clean (no strict violations; warnings allowed)
 *   1  strict violations present
 *   2  configuration problem
 *
 * Runs over every PHP file under the configured scan roots
 * (default: app/). Each rule describes a pattern and where it is
 * allowed. See config/ledger-discipline.php.
 *
 * Intended CI wiring (GitHub Actions / GitLab / etc.):
 *   - run: php artisan ledger:guard
 */
class LedgerGuard extends Command
{
    protected $signature = 'ledger:guard
                            {--strict : Fail on warnings too}
                            {--warn-only : Never fail, only report}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Enforce ledger write discipline (L-017 CI guard)';

    public function handle(LedgerDisciplineScanner $scanner): int
    {
        $config = config('ledger-discipline');
        if (! is_array($config) || empty($config['rules'])) {
            $this->error('ledger-discipline config missing or empty');
            return 2;
        }

        $result = $scanner->scan(
            rules:     $config['rules'],
            scanRoots: $config['scan_roots'] ?? ['app/'],
            baseDir:   base_path(),
        );

        // Apply mode overrides.
        $strictMode = (bool) $this->option('strict');
        $warnOnly   = (bool) $this->option('warn-only');
        if ($strictMode && $warnOnly) {
            $this->error('--strict and --warn-only are mutually exclusive');
            return 2;
        }

        $effective = [];
        foreach ($result['violations'] as $v) {
            if ($warnOnly) {
                $v['severity'] = 'warn';
            } elseif ($strictMode) {
                $v['severity'] = 'strict';
            }
            $effective[] = $v;
        }

        $strictCount = count(array_filter($effective, fn ($v) => $v['severity'] === 'strict'));
        $warnCount   = count(array_filter($effective, fn ($v) => $v['severity'] === 'warn'));

        // Always log a structured summary (cron + dashboard friendly).
        Log::info('ledger.guard.scan', [
            'files_scanned' => $result['files_scanned'],
            'violations'    => count($effective),
            'strict'        => $strictCount,
            'warn'          => $warnCount,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode([
                'files_scanned' => $result['files_scanned'],
                'strict_count'  => $strictCount,
                'warn_count'    => $warnCount,
                'violations'    => $effective,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->printHuman($effective, $result['files_scanned'], $strictCount, $warnCount);
        }

        return $strictCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printHuman(array $violations, int $filesScanned, int $strictCount, int $warnCount): void
    {
        $this->line('Ledger discipline scan');
        $this->line('======================');
        $this->line("Files scanned: {$filesScanned}");
        $this->line('');

        if ($violations === []) {
            $this->info('✅ Clean — no violations found.');
            return;
        }

        // Group by rule_id for readable output.
        $byRule = [];
        foreach ($violations as $v) {
            $byRule[$v['rule_id']][] = $v;
        }

        foreach ($byRule as $ruleId => $rows) {
            $first    = $rows[0];
            $severity = $first['severity'];
            $marker   = $severity === 'strict' ? '🔴' : '⚠️';
            $label    = strtoupper($severity);

            $this->line("{$marker} {$ruleId} [{$label}] {$first['description']}");

            foreach ($rows as $row) {
                $this->line(sprintf('   %s:%d  %s', $row['file'], $row['line'], $row['match']));
            }

            if (! empty($first['remediation'])) {
                $this->line("   → {$first['remediation']}");
            }
            $this->line('');
        }

        $this->line("Summary: {$strictCount} error(s), {$warnCount} warning(s)");
        if ($strictCount > 0) {
            $this->error('FAIL — strict violations present');
        } else {
            $this->info('PASS — warnings only, no failures');
        }
    }
}
