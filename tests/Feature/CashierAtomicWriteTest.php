<?php

namespace Tests\Feature;

use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\CashExpense;
use App\Models\EndSaldo;
use App\Models\ShiftHandover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierAtomicWriteTest extends TestCase
{
    use RefreshDatabase;

    private function createOpenShift(): CashierShift
    {
        $drawer = CashDrawer::create([
            'name' => 'Test Drawer',
            'is_active' => true,
        ]);

        $user = User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    // ── Exchange atomicity ──────────────────────────────

    public function test_exchange_creates_both_in_and_out_transactions(): void
    {
        $shift = $this->createOpenShift();

        DB::transaction(function () use ($shift) {
            CashTransaction::create([
                'cashier_shift_id' => $shift->id,
                'type' => 'in', 'amount' => 100, 'currency' => 'USD',
                'related_currency' => 'UZS', 'related_amount' => 1280000,
                'category' => 'exchange', 'reference' => 'EX-TEST',
                'created_by' => $shift->user_id, 'occurred_at' => now(),
            ]);

            CashTransaction::create([
                'cashier_shift_id' => $shift->id,
                'type' => 'out', 'amount' => 1280000, 'currency' => 'UZS',
                'related_currency' => 'USD', 'related_amount' => 100,
                'category' => 'exchange', 'reference' => 'EX-TEST',
                'created_by' => $shift->user_id, 'occurred_at' => now(),
            ]);
        });

        $this->assertEquals(2, CashTransaction::where('reference', 'EX-TEST')->count());
    }

    public function test_exchange_rolls_back_if_second_insert_fails(): void
    {
        $shift = $this->createOpenShift();

        try {
            DB::transaction(function () use ($shift) {
                CashTransaction::create([
                    'cashier_shift_id' => $shift->id,
                    'type' => 'in', 'amount' => 100, 'currency' => 'USD',
                    'category' => 'exchange', 'reference' => 'EX-ROLLBACK',
                    'created_by' => $shift->user_id, 'occurred_at' => now(),
                ]);

                // Force failure on second insert
                throw new \RuntimeException('Simulated second-insert failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Neither transaction should exist
        $this->assertEquals(0, CashTransaction::where('reference', 'EX-ROLLBACK')->count());
    }

    // ── Shift close atomicity ───────────────────────────

    public function test_close_shift_creates_handover_and_end_saldos_atomically(): void
    {
        $shift = $this->createOpenShift();

        DB::transaction(function () use ($shift) {
            CashierShift::where('id', $shift->id)->lockForUpdate()->first();

            ShiftHandover::create([
                'outgoing_shift_id' => $shift->id,
                'counted_uzs' => 500000, 'counted_usd' => 50, 'counted_eur' => 0,
                'expected_uzs' => 480000, 'expected_usd' => 50, 'expected_eur' => 0,
            ]);

            $shift->update(['status' => 'closed', 'closed_at' => now()]);

            EndSaldo::create([
                'cashier_shift_id' => $shift->id,
                'currency' => 'UZS',
                'expected_end_saldo' => 480000,
                'counted_end_saldo' => 500000,
                'discrepancy' => 20000,
            ]);
        });

        $shift->refresh();
        $this->assertEquals('closed', $shift->status);
        $this->assertEquals(1, ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
        $this->assertEquals(1, EndSaldo::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_close_shift_rolls_back_completely_on_failure(): void
    {
        $shift = $this->createOpenShift();

        try {
            DB::transaction(function () use ($shift) {
                ShiftHandover::create([
                    'outgoing_shift_id' => $shift->id,
                    'counted_uzs' => 500000, 'counted_usd' => 0, 'counted_eur' => 0,
                    'expected_uzs' => 500000, 'expected_usd' => 0, 'expected_eur' => 0,
                ]);

                $shift->update(['status' => 'closed', 'closed_at' => now()]);

                // Simulate failure before EndSaldo
                throw new \RuntimeException('Simulated close failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $shift->refresh();
        // Shift should still be open
        $this->assertEquals('open', $shift->status);
        // No handover should exist
        $this->assertEquals(0, ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
    }

    // ── Expense atomicity ───────────────────────────────

    public function test_expense_creates_both_expense_and_transaction_atomically(): void
    {
        $shift = $this->createOpenShift();
        $catId = DB::table('expense_categories')->insertGetId([
            'name' => 'Test Category', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::transaction(function () use ($shift, $catId) {
            CashExpense::create([
                'cashier_shift_id' => $shift->id, 'expense_category_id' => $catId,
                'amount' => 50000, 'currency' => 'UZS', 'description' => 'Test expense',
                'created_by' => $shift->user_id, 'occurred_at' => now(),
            ]);

            CashTransaction::create([
                'cashier_shift_id' => $shift->id, 'type' => 'out', 'amount' => 50000,
                'currency' => 'UZS', 'category' => 'expense',
                'reference' => 'Расход: Test', 'notes' => 'Test expense',
                'created_by' => $shift->user_id, 'occurred_at' => now(),
            ]);
        });

        $this->assertEquals(1, CashExpense::where('cashier_shift_id', $shift->id)->count());
        $this->assertEquals(1, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_expense_rolls_back_if_transaction_insert_fails(): void
    {
        $shift = $this->createOpenShift();
        $catId = DB::table('expense_categories')->insertGetId([
            'name' => 'Test Category', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($shift, $catId) {
                CashExpense::create([
                    'cashier_shift_id' => $shift->id, 'expense_category_id' => $catId,
                    'amount' => 50000, 'currency' => 'UZS', 'description' => 'Rollback test',
                    'created_by' => $shift->user_id, 'occurred_at' => now(),
                ]);

                // Simulate failure on linked transaction
                throw new \RuntimeException('Simulated transaction-insert failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Both should be rolled back
        $this->assertEquals(0, CashExpense::where('description', 'Rollback test')->count());
        $this->assertEquals(0, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    // ── Idempotency + transaction interaction ───────────

    public function test_idempotency_succeeds_inside_committed_transaction(): void
    {
        $callbackId = 'cb_atomic_' . uniqid();

        DB::transaction(function () use ($callbackId) {
            DB::table('telegram_processed_callbacks')->insert([
                'callback_query_id' => $callbackId,
                'chat_id' => 12345,
                'action' => 'confirm_payment',
                'status' => 'processing',
                'claimed_at' => now(),
            ]);

            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', $callbackId)
                ->update(['status' => 'succeeded', 'completed_at' => now()]);
        });

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'succeeded',
        ]);
    }

    public function test_idempotency_rolls_back_with_transaction(): void
    {
        $callbackId = 'cb_rollback_' . uniqid();

        // Pre-claim the callback
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_payment',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($callbackId) {
                // Succeed callback inside transaction
                DB::table('telegram_processed_callbacks')
                    ->where('callback_query_id', $callbackId)
                    ->update(['status' => 'succeeded', 'completed_at' => now()]);

                // Then the financial write fails
                throw new \RuntimeException('Simulated DB failure after succeed');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Callback should remain 'processing' (rolled back with transaction)
        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'processing',
        ]);
    }
}
