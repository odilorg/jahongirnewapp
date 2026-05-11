<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\CashExpense;
use App\Models\Expense;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Expenses\ConsolidatePettyCashService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for monthly petty-cash → main expenses consolidation (v1).
 *
 * v1 scope:
 *   - UZS-only (FX rows skipped, counted)
 *   - Per-row unpost with required reason
 *   - Re-consolidation after unpost creates a NEW expense row
 */
class ConsolidatePettyCashTest extends TestCase
{
    use RefreshDatabase;

    public function test_consolidates_uzs_rows_and_marks_source_consolidated(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $cash1 = CashExpense::factory()->create([
            'amount' => 50000.00,
            'currency' => 'UZS',
            'description' => 'Fuel — April 3',
            'occurred_at' => Carbon::parse('2026-04-03 10:00'),
        ]);
        $cash2 = CashExpense::factory()->create([
            'amount' => 120000.00,
            'currency' => 'UZS',
            'description' => 'Cleaning supplies',
            'occurred_at' => Carbon::parse('2026-04-20 14:30'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $result = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(2, $result['posted']);
        $this->assertSame(0, $result['skipped_fx']);
        $this->assertSame(0, $result['skipped_invalid']);

        // Source rows stamped + linked to the new expense rows
        $this->assertNotNull($cash1->fresh()->consolidated_at);
        $this->assertNotNull($cash1->fresh()->consolidated_expense_id);
        $this->assertNotNull($cash2->fresh()->consolidated_at);
        $this->assertNotNull($cash2->fresh()->consolidated_expense_id);

        // Target rows correct
        $expense1 = Expense::where('cash_expense_id', $cash1->id)->firstOrFail();
        $this->assertSame($hotel->id, $expense1->hotel_id);
        $this->assertSame('naqd', $expense1->payment_type);
        $this->assertSame($owner->id, $expense1->created_by);
        $this->assertSame($cash1->expense_category_id, $expense1->expense_category_id);
        $this->assertSame('2026-04-03', $expense1->expense_date->toDateString());
        $this->assertEquals(50000.00, (float) $expense1->amount);
        $this->assertStringContainsString('[Petty cash]', $expense1->name);
        $this->assertStringContainsString('Fuel', $expense1->name);

        // Two-way link verified
        $this->assertSame($expense1->id, $cash1->fresh()->consolidated_expense_id);
    }

    public function test_second_run_is_idempotent_and_posts_zero(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        CashExpense::factory()->count(3)->create([
            'amount' => 30000.00,
            'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-10 09:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);

        $firstRun = $service->consolidateMonth($period, $hotel->id, $owner->id);
        $secondRun = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(3, $firstRun['posted']);
        $this->assertSame(0, $secondRun['posted'], 'Re-running consolidation must skip already-consolidated rows.');
        $this->assertSame(3, Expense::count());
    }

    public function test_rejected_rows_are_not_consolidated(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $rejected = CashExpense::factory()->create([
            'amount' => 80000.00,
            'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-05 10:00'),
            'rejected_at' => Carbon::parse('2026-04-06 09:00'),
            'rejected_by' => $owner->id,
            'rejection_reason' => 'Not a business expense',
        ]);
        $approved = CashExpense::factory()->create([
            'amount' => 40000.00,
            'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-08 10:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $result = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(1, $result['posted']);
        $this->assertNull($rejected->fresh()->consolidated_at);
        $this->assertNotNull($approved->fresh()->consolidated_at);
        $this->assertSame(0, Expense::where('cash_expense_id', $rejected->id)->count());
    }

    public function test_fx_rows_are_skipped_and_counted(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $uzs = CashExpense::factory()->create([
            'amount' => 25000.00, 'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-02 10:00'),
        ]);
        $usd = CashExpense::factory()->create([
            'amount' => 25.00, 'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-11 10:00'),
        ]);
        $eur = CashExpense::factory()->create([
            'amount' => 18.00, 'currency' => 'EUR',
            'occurred_at' => Carbon::parse('2026-04-14 10:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $result = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(1, $result['posted']);
        $this->assertSame(2, $result['skipped_fx']);

        // FX rows remain eligible for future consolidation (not marked consolidated)
        $this->assertNull($usd->fresh()->consolidated_at);
        $this->assertNull($eur->fresh()->consolidated_at);
        $this->assertNotNull($uzs->fresh()->consolidated_at);

        // Only one expense row created — the UZS one
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, Expense::where('cash_expense_id', $uzs->id)->count());
    }

    public function test_unpost_soft_deletes_expense_and_clears_source_pointer(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $cash = CashExpense::factory()->create([
            'amount' => 70000.00,
            'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-12 10:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $service->consolidateMonth($period, $hotel->id, $owner->id);

        $expense = Expense::where('cash_expense_id', $cash->id)->firstOrFail();
        $service->unpostExpense($expense->id, 'wrong category selected');

        // Expense soft-deleted (no longer visible in default scope)
        $this->assertNull(Expense::find($expense->id));
        $this->assertNotNull(Expense::withTrashed()->find($expense->id));
        $this->assertNotNull(Expense::withTrashed()->find($expense->id)->deleted_at);

        // Source pointer cleared, unpost trail stamped
        $fresh = $cash->fresh();
        $this->assertNull($fresh->consolidated_at);
        $this->assertNull($fresh->consolidated_expense_id);
        $this->assertNotNull($fresh->consolidation_unposted_at);
        $this->assertSame('wrong category selected', $fresh->consolidation_unposted_reason);
    }

    public function test_re_consolidation_after_unpost_creates_new_expense_row(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $cash = CashExpense::factory()->create([
            'amount' => 55000.00,
            'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-15 10:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $service->consolidateMonth($period, $hotel->id, $owner->id);
        $firstExpense = Expense::where('cash_expense_id', $cash->id)->firstOrFail();

        $service->unpostExpense($firstExpense->id, 'redo with correct hotel');

        // Re-consolidate
        $result = $service->consolidateMonth($period, $hotel->id, $owner->id);
        $this->assertSame(1, $result['posted']);

        $secondExpense = Expense::where('cash_expense_id', $cash->id)->firstOrFail();
        $this->assertNotSame($firstExpense->id, $secondExpense->id, 'Re-consolidation must produce a NEW expense row, not restore the trashed one.');

        // Original soft-deleted row preserved as audit trail
        $this->assertNotNull(Expense::withTrashed()->find($firstExpense->id)->deleted_at);

        // Source row points at the new expense; unpost trail cleared on re-post
        $fresh = $cash->fresh();
        $this->assertSame($secondExpense->id, $fresh->consolidated_expense_id);
        $this->assertNull($fresh->consolidation_unposted_at);
        $this->assertNull($fresh->consolidation_unposted_reason);
    }

    public function test_unpost_requires_reason_minimum_length(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        $cash = CashExpense::factory()->create([
            'amount' => 10000.00, 'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-15 10:00'),
        ]);
        $service = app(ConsolidatePettyCashService::class);
        $service->consolidateMonth($period, $hotel->id, $owner->id);
        $expense = Expense::where('cash_expense_id', $cash->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        $service->unpostExpense($expense->id, 'no');
    }

    public function test_unpost_rejects_non_petty_cash_expense(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();

        // Direct ops/owner expense — no cash_expense_id link
        $direct = Expense::create([
            'expense_category_id' => \App\Models\ExpenseCategory::factory()->create()->id,
            'name' => 'Direct hotel expense',
            'expense_date' => '2026-04-10',
            'amount' => 100000.00,
            'hotel_id' => $hotel->id,
            'payment_type' => 'karta',
            'created_by' => $owner->id,
        ]);

        $service = app(ConsolidatePettyCashService::class);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->unpostExpense($direct->id, 'should be rejected by the filter');
    }

    /**
     * Build a fully-valid Hotel row. The hotels table has many NOT NULL columns
     * added by later migrations; supplying explicit values keeps the test
     * independent of unrelated schema drift.
     */
    private function makeHotel(): Hotel
    {
        return Hotel::create([
            'name' => 'Test Hotel',
            'description' => 'Test',
            'room_quantity' => 10,
            'number_people' => 20,
            'location' => 'Samarkand',
            'address' => '1 Test St',
            'phone' => '+998000000000',
            'email' => 'test@example.com',
            'website' => 'https://example.com',
            'official_name' => 'Test LLC',
            'account_number' => '0000',
            'bank_name' => 'Test Bank',
            'inn' => '000000000',
            'director_name' => 'Test Director',
        ]);
    }
}
