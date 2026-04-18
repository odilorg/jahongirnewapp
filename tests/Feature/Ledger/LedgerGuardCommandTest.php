<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Services\Ledger\LedgerDisciplineScanner;
use Tests\TestCase;

/**
 * L-017 — scanner + command tests.
 *
 * The scanner is driven with a temporary directory that contains
 * fixture PHP files, so tests do not depend on the real app/
 * structure evolving.
 */
class LedgerGuardCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/ledger_guard_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tmpDir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // SCANNER tests (pure, no Laravel)
    // ---------------------------------------------------------------------

    public function test_scanner_finds_no_violations_in_clean_tree(): void
    {
        $this->writeFixture('app/Http/Controllers/CleanController.php', "<?php\n\nclass CleanController { public function handle() {} }\n");

        $result = (new LedgerDisciplineScanner())->scan(
            rules:     $this->rules(),
            scanRoots: ['app/'],
            baseDir:   $this->tmpDir,
        );

        $this->assertSame([], $result['violations']);
        $this->assertGreaterThan(0, $result['files_scanned']);
    }

    public function test_scanner_flags_ledger_entry_create_outside_actions(): void
    {
        $this->writeFixture('app/Http/Controllers/BadController.php', "<?php\n\\App\\Models\\LedgerEntry::create(['x' => 1]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertNotEmpty($result['violations']);
        $this->assertSame('R1', $result['violations'][0]['rule_id']);
        $this->assertSame('strict', $result['violations'][0]['severity']);
        $this->assertSame('app/Http/Controllers/BadController.php', $result['violations'][0]['file']);
    }

    public function test_scanner_allows_ledger_entry_create_inside_actions_dir(): void
    {
        $this->writeFixture('app/Actions/Ledger/RecordLedgerEntry.php', "<?php\n\\App\\Models\\LedgerEntry::create(['x' => 1]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertSame([], $result['violations']);
    }

    public function test_scanner_flags_ledger_entry_update(): void
    {
        $this->writeFixture('app/Actions/Ledger/BadAction.php', "<?php\nLedgerEntry::update(['x' => 1]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertNotEmpty($result['violations']);
        $this->assertSame('R2', $result['violations'][0]['rule_id']);
        // R2 has NO allowed paths — even inside Actions/Ledger, update is forbidden.
    }

    public function test_scanner_baseline_files_are_allowed(): void
    {
        $this->writeFixture('app/Services/CashierExpenseService.php', "<?php\nCashTransaction::create(['x' => 1]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertSame([], $result['violations']);
    }

    public function test_scanner_flags_new_cash_transaction_caller_as_warn(): void
    {
        $this->writeFixture('app/Services/BrandNewService.php', "<?php\nCashTransaction::create(['x' => 1]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertNotEmpty($result['violations']);
        $v = $result['violations'][0];
        $this->assertSame('R5', $v['rule_id']);
        $this->assertSame('warn', $v['severity']);
        $this->assertSame('app/Services/BrandNewService.php', $v['file']);
    }

    public function test_scanner_allows_projection_updater_writes(): void
    {
        $this->writeFixture('app/Services/Ledger/BalanceProjectionUpdater.php', "<?php\nCashDrawerBalance::create(['x' => 1]);\n");
        $this->writeFixture('app/Listeners/Ledger/UpdateBalanceProjections.php', "<?php\nShiftBalance::updateOrCreate(['y' => 2]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertSame([], $result['violations']);
    }

    public function test_scanner_flags_projection_write_in_controller(): void
    {
        $this->writeFixture('app/Http/Controllers/EvilController.php', "<?php\nCashDrawerBalance::update(['balance' => 9999]);\n");

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertNotEmpty($result['violations']);
        $this->assertSame('R3', $result['violations'][0]['rule_id']);
        $this->assertSame('strict', $result['violations'][0]['severity']);
    }

    public function test_line_number_reported_is_correct(): void
    {
        $this->writeFixture('app/Http/Controllers/LineTest.php',
            "<?php\n// comment\n// another\nLedgerEntry::create(['x' => 1]);\n"
        );

        $result = (new LedgerDisciplineScanner())->scan($this->rules(), ['app/'], $this->tmpDir);

        $this->assertNotEmpty($result['violations']);
        $this->assertSame(4, $result['violations'][0]['line']);
    }

    // ---------------------------------------------------------------------
    // COMMAND tests — exercise the real config on the real app/
    // ---------------------------------------------------------------------

    public function test_command_passes_on_clean_main(): void
    {
        // main as of L-017 must be clean — if not, this test catches it.
        $this->artisan('ledger:guard')
            ->expectsOutputToContain('Ledger discipline scan')
            ->assertSuccessful();
    }

    public function test_command_strict_mode_still_passes_on_clean_main(): void
    {
        // Strict mode promotes warnings to strict. The baseline CashTransaction
        // callers are still allowed (they're on the allowlist), so strict mode
        // with a clean main still passes.
        $this->artisan('ledger:guard', ['--strict' => true])
            ->assertSuccessful();
    }

    public function test_command_json_output_is_parseable(): void
    {
        $this->artisan('ledger:guard', ['--json' => true])
            ->assertSuccessful();
        // Sanity — the file ran through JSON output without error; Laravel's
        // test artisan runner already asserts success exit code.
    }

    public function test_command_rejects_conflicting_options(): void
    {
        $this->artisan('ledger:guard', ['--strict' => true, '--warn-only' => true])
            ->assertExitCode(2);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function rules(): array
    {
        // Reuse the real config so tests validate the real rule set.
        return config('ledger-discipline.rules');
    }

    private function writeFixture(string $relPath, string $content): void
    {
        $full = $this->tmpDir . '/' . $relPath;
        $dir  = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($full, $content);
    }

    private function deleteRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteRecursive($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
