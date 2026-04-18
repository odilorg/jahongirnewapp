<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Actions\Ledger\RecordLedgerEntry;
use App\DTOs\Ledger\LedgerEntryInput;
use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Models\Projections\CashDrawerBalance;
use App\Models\Projections\ShiftBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerRebuildProjectionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_regenerates_identical_balances(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $this->writeHistory($drawerId, $shiftId, $userId);

        $liveDrawerBalance = (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->value('balance');
        $liveShiftBalance  = (string) ShiftBalance::where('cashier_shift_id', $shiftId)->where('currency', 'USD')->value('balance');

        // Rebuild truncates + re-applies.
        $this->artisan('ledger:rebuild-projections')
            ->expectsOutputToContain('Rebuilding ledger balance projections')
            ->assertSuccessful();

        $rebuiltDrawer = (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->value('balance');
        $rebuiltShift  = (string) ShiftBalance::where('cashier_shift_id', $shiftId)->where('currency', 'USD')->value('balance');

        $this->assertSame($liveDrawerBalance, $rebuiltDrawer);
        $this->assertSame($liveShiftBalance, $rebuiltShift);
    }

    public function test_rebuild_is_idempotent(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $this->writeHistory($drawerId, $shiftId, $userId);

        $first = (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->value('balance');

        $this->artisan('ledger:rebuild-projections')->assertSuccessful();
        $this->artisan('ledger:rebuild-projections')->assertSuccessful();

        $twice = (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->value('balance');
        $this->assertSame($first, $twice);
        $this->assertSame(1, CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->count());
    }

    public function test_verify_reports_zero_drift_on_clean_state(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $this->writeHistory($drawerId, $shiftId, $userId);

        $this->artisan('ledger:rebuild-projections', ['--verify' => true])
            ->expectsOutputToContain('Verify: zero drift')
            ->assertSuccessful();
    }

    public function test_verify_detects_manual_tampering(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $this->writeHistory($drawerId, $shiftId, $userId);

        // Manually corrupt the projection to simulate drift.
        DB::table('cash_drawer_balances')
            ->where('cash_drawer_id', $drawerId)
            ->where('currency', 'USD')
            ->update(['balance' => 99999.99]);

        $this->artisan('ledger:rebuild-projections', ['--verify' => true])
            ->assertFailed();
    }

    // ---------------------------------------------------------------------

    private function seedDrawerAndShift(): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Test', 'email' => 'u_' . uniqid() . '@example.com',
            'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $drawerColumns = \Schema::getColumnListing('cash_drawers');
        $drawerInsert = ['name' => 'Reception-' . uniqid(), 'created_at' => now(), 'updated_at' => now()];
        if (in_array('is_active', $drawerColumns, true))   { $drawerInsert['is_active']   = 1; }
        if (in_array('location_id', $drawerColumns, true)) { $drawerInsert['location_id'] = null; }
        $drawerId = DB::table('cash_drawers')->insertGetId($drawerInsert);

        $shiftId = DB::table('cashier_shifts')->insertGetId([
            'cash_drawer_id' => $drawerId, 'user_id' => $userId, 'status' => 'open',
            'opened_at' => now(), 'beginning_saldo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$drawerId, $shiftId, $userId];
    }

    private function writeHistory(int $drawerId, int $shiftId, int $userId): void
    {
        $action = app(RecordLedgerEntry::class);
        foreach ([100.0, 50.0, 25.0] as $amount) {
            $action->execute(new LedgerEntryInput(
                entryType:         LedgerEntryType::AccommodationPaymentIn,
                source:            SourceTrigger::CashierBot,
                amount:            $amount,
                currency:          Currency::USD,
                counterpartyType:  CounterpartyType::Guest,
                paymentMethod:     PaymentMethod::Cash,
                cashierShiftId:    $shiftId,
                cashDrawerId:      $drawerId,
                externalReference: 'ref_' . uniqid(),
                createdByUserId:   $userId,
                createdByBotSlug:  'cashier',
            ));
        }

        $action->execute(new LedgerEntryInput(
            entryType:         LedgerEntryType::OperationalExpense,
            source:            SourceTrigger::CashierBot,
            amount:            20.0,
            currency:          Currency::USD,
            counterpartyType:  CounterpartyType::Internal,
            paymentMethod:     PaymentMethod::Cash,
            cashierShiftId:    $shiftId,
            cashDrawerId:      $drawerId,
            externalReference: 'exp_' . uniqid(),
            createdByUserId:   $userId,
            createdByBotSlug:  'cashier',
        ));
    }
}
