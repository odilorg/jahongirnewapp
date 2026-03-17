<?php

namespace Tests\Unit;

use App\Models\CashDrawer;
use App\Models\CashExpense;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\CashierExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashierExpenseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashierExpenseService();
    }

    private function createOpenShift(): CashierShift
    {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user = \App\Models\User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function createCategory(): int
    {
        return DB::table('expense_categories')->insertGetId([
            'name' => 'Test Category', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_records_expense_with_linked_transaction(): void
    {
        $shift = $this->createOpenShift();
        $catId = $this->createCategory();

        $expense = $this->service->recordExpense($shift->id, [
            'cat_id' => $catId,
            'cat_name' => 'Test Category',
            'amount' => 50000,
            'currency' => 'UZS',
            'desc' => 'Office supplies',
            'needs_approval' => false,
        ], $shift->user_id);

        $this->assertInstanceOf(CashExpense::class, $expense);
        $this->assertEquals(50000, $expense->amount);
        $this->assertEquals('Office supplies', $expense->description);

        // Linked out-transaction must exist
        $tx = CashTransaction::where('cashier_shift_id', $shift->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('out', $tx->type);
        $this->assertEquals(50000, $tx->amount);
        $this->assertEquals('expense', $tx->category);
    }

    public function test_rejects_expense_on_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        $catId = $this->createCategory();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift closed during confirmation');

        $this->service->recordExpense($shift->id, [
            'cat_id' => $catId, 'cat_name' => 'X', 'amount' => 100,
            'currency' => 'UZS', 'desc' => 'test',
        ], $shift->user_id);
    }

    public function test_no_records_written_when_shift_closed(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        $catId = $this->createCategory();

        try {
            $this->service->recordExpense($shift->id, [
                'cat_id' => $catId, 'cat_name' => 'X', 'amount' => 100,
                'currency' => 'UZS', 'desc' => 'rollback test',
            ], $shift->user_id);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertEquals(0, CashExpense::where('cashier_shift_id', $shift->id)->count());
        $this->assertEquals(0, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_rolls_back_both_records_on_failure(): void
    {
        $shift = $this->createOpenShift();

        // Use invalid cat_id to trigger DB error on CashExpense::create
        try {
            DB::transaction(function () use ($shift) {
                CashExpense::create([
                    'cashier_shift_id' => $shift->id, 'expense_category_id' => 999999,
                    'amount' => 100, 'currency' => 'UZS', 'description' => 'fail',
                    'created_by' => $shift->user_id, 'occurred_at' => now(),
                ]);

                CashTransaction::create([
                    'cashier_shift_id' => $shift->id, 'type' => 'out', 'amount' => 100,
                    'currency' => 'UZS', 'category' => 'expense', 'reference' => 'X',
                    'created_by' => $shift->user_id, 'occurred_at' => now(),
                ]);

                throw new \RuntimeException('Simulated failure');
            });
        } catch (\Exception $e) {
            // expected
        }

        $this->assertEquals(0, CashExpense::where('cashier_shift_id', $shift->id)->count());
        $this->assertEquals(0, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_succeeds_callback_inside_transaction(): void
    {
        $shift = $this->createOpenShift();
        $catId = $this->createCategory();
        $callbackId = 'cb_exp_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_expense',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        $this->service->recordExpense($shift->id, [
            'cat_id' => $catId, 'cat_name' => 'Test', 'amount' => 100,
            'currency' => 'UZS', 'desc' => 'cb test',
        ], $shift->user_id, $callbackId);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'succeeded',
        ]);
    }

    public function test_sets_requires_approval_flag(): void
    {
        $shift = $this->createOpenShift();
        $catId = $this->createCategory();

        $expense = $this->service->recordExpense($shift->id, [
            'cat_id' => $catId, 'cat_name' => 'Test', 'amount' => 1000000,
            'currency' => 'UZS', 'desc' => 'big purchase', 'needs_approval' => true,
        ], $shift->user_id);

        $this->assertTrue((bool) $expense->requires_approval);
    }
}
