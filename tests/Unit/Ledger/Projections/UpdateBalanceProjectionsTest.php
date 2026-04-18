<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger\Projections;

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

/**
 * L-005 — verify the event listener keeps balance projections in sync.
 *
 * End-to-end: RecordLedgerEntry fires LedgerEntryRecorded →
 * UpdateBalanceProjections listener → BalanceProjectionUpdater →
 * projection row is created / mutated.
 */
class UpdateBalanceProjectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_entry_creates_drawer_balance_row(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();

        app(RecordLedgerEntry::class)->execute($this->input(
            drawerId:        $drawerId,
            shiftId:         $shiftId,
            userId:          $userId,
            direction:       LedgerEntryDirection::In,
            amount:          100.0,
        ));

        $balance = CashDrawerBalance::where('cash_drawer_id', $drawerId)
            ->where('currency', 'USD')->first();

        $this->assertNotNull($balance);
        $this->assertSame('100.00', (string) $balance->balance);
        $this->assertSame('100.00', (string) $balance->total_in);
        $this->assertSame('0.00',   (string) $balance->total_out);
        $this->assertSame(1,        $balance->in_count);
        $this->assertSame(0,        $balance->out_count);
    }

    public function test_sequential_entries_accumulate_correctly(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $action = app(RecordLedgerEntry::class);

        $action->execute($this->input(drawerId: $drawerId, shiftId: $shiftId, userId: $userId, direction: LedgerEntryDirection::In,  amount: 100.0));
        $action->execute($this->input(drawerId: $drawerId, shiftId: $shiftId, userId: $userId, direction: LedgerEntryDirection::In,  amount: 50.0));
        $action->execute($this->input(drawerId: $drawerId, shiftId: $shiftId, userId: $userId, direction: LedgerEntryDirection::Out, amount: 30.0, entryType: LedgerEntryType::OperationalExpense));

        $balance = CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->first();
        $this->assertSame('120.00', (string) $balance->balance);  // 100 + 50 - 30
        $this->assertSame('150.00', (string) $balance->total_in);
        $this->assertSame('30.00',  (string) $balance->total_out);
        $this->assertSame(2,        $balance->in_count);
        $this->assertSame(1,        $balance->out_count);
    }

    public function test_different_currencies_are_tracked_separately(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();
        $action = app(RecordLedgerEntry::class);

        $action->execute($this->input(drawerId: $drawerId, shiftId: $shiftId, userId: $userId, currency: Currency::USD, amount: 100.0));
        $action->execute($this->input(drawerId: $drawerId, shiftId: $shiftId, userId: $userId, currency: Currency::UZS, amount: 1_280_000.0));

        $this->assertSame(2, CashDrawerBalance::where('cash_drawer_id', $drawerId)->count());
        $this->assertSame('100.00',       (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->value('balance'));
        $this->assertSame('1280000.00',   (string) CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'UZS')->value('balance'));
    }

    public function test_shift_balance_is_maintained_independently(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();

        app(RecordLedgerEntry::class)->execute($this->input(
            drawerId: $drawerId, shiftId: $shiftId, userId: $userId,
            direction: LedgerEntryDirection::In, amount: 77.0,
        ));

        $shiftBal = ShiftBalance::where('cashier_shift_id', $shiftId)->where('currency', 'USD')->first();
        $this->assertNotNull($shiftBal);
        $this->assertSame('77.00', (string) $shiftBal->balance);

        $drawerBal = CashDrawerBalance::where('cash_drawer_id', $drawerId)->where('currency', 'USD')->first();
        $this->assertSame('77.00', (string) $drawerBal->balance);
    }

    public function test_entry_without_drawer_or_shift_skips_projections(): void
    {
        $this->seedDrawerAndShift(); // set up but don't reference in entry

        // Payment via Octo — has booking_inquiry_id but no drawer/shift.
        app(RecordLedgerEntry::class)->execute(new LedgerEntryInput(
            entryType:         LedgerEntryType::AccommodationPaymentIn,
            source:            SourceTrigger::OctoCallback,
            amount:            200.0,
            currency:          Currency::USD,
            counterpartyType:  CounterpartyType::Guest,
            paymentMethod:     PaymentMethod::OctoOnline,
            idempotencyKey:    'octo_abc',
            externalReference: 'octo_abc',
            createdByBotSlug:  'octo_callback',
        ));

        $this->assertSame(0, CashDrawerBalance::count());
        $this->assertSame(0, ShiftBalance::count());
    }

    public function test_listener_update_is_atomic_with_ledger_write(): void
    {
        [$drawerId, $shiftId, $userId] = $this->seedDrawerAndShift();

        // Registering an additional listener that throws simulates a
        // projection-downstream failure. Laravel fires listeners in the
        // order they are registered; an exception from any listener
        // inside DB::transaction should roll back everything.
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Ledger\LedgerEntryRecorded::class,
            function (): void { throw new \RuntimeException('downstream exploded'); }
        );

        try {
            app(RecordLedgerEntry::class)->execute($this->input(
                drawerId: $drawerId, shiftId: $shiftId, userId: $userId,
                direction: LedgerEntryDirection::In, amount: 10.0,
            ));
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // The ledger row must not exist AND the projection must
            // remain empty — the whole transaction rolled back.
            $this->assertSame(0, \App\Models\LedgerEntry::count());
            $this->assertSame(0, CashDrawerBalance::count());
            $this->assertSame(0, ShiftBalance::count());
        }
    }

    // ---------------------------------------------------------------------

    private function seedDrawerAndShift(): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Test Cashier',
            'email' => 'cashier_' . uniqid() . '@example.com',
            'password' => bcrypt('x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $drawerColumns = \Schema::getColumnListing('cash_drawers');
        $drawerInsert = [
            'name' => 'Reception-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (in_array('is_active', $drawerColumns, true))  { $drawerInsert['is_active']  = 1; }
        if (in_array('location_id', $drawerColumns, true)) { $drawerInsert['location_id'] = null; }
        $drawerId = DB::table('cash_drawers')->insertGetId($drawerInsert);

        $shiftId = DB::table('cashier_shifts')->insertGetId([
            'cash_drawer_id'  => $drawerId,
            'user_id'         => $userId,
            'status'          => 'open',
            'opened_at'       => now(),
            'beginning_saldo' => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return [$drawerId, $shiftId, $userId];
    }

    private function input(
        int                   $drawerId,
        int                   $shiftId,
        int                   $userId,
        ?LedgerEntryDirection $direction = null,
        ?LedgerEntryType      $entryType = null,
        ?Currency             $currency = null,
        ?float                $amount = null,
    ): LedgerEntryInput {
        return new LedgerEntryInput(
            entryType:         $entryType ?? LedgerEntryType::AccommodationPaymentIn,
            source:            SourceTrigger::CashierBot,
            amount:            $amount ?? 100.0,
            currency:          $currency ?? Currency::USD,
            counterpartyType:  CounterpartyType::Guest,
            paymentMethod:     PaymentMethod::Cash,
            direction:         $direction,
            cashierShiftId:    $shiftId,
            cashDrawerId:      $drawerId,
            externalReference: 'test_ref_' . uniqid(),
            createdByUserId:   $userId,
            createdByBotSlug:  'cashier',
        );
    }
}
