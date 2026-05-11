<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\CashExpense;
use App\Models\Expense;
use App\Models\Hotel;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Expenses\ConsolidatePettyCashService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Feature tests for the petty-cash → main expenses monthly consolidation flow.
 *
 * Scope kept tight per operator scope discipline:
 *   1. happy path  — UZS rows post and source rows get stamped
 *   2. idempotency — re-running posts 0
 *   3. FX path     — USD rows convert via ExchangeRateService (cached)
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
        $posted = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(2, $posted);

        // Source rows stamped
        $this->assertNotNull($cash1->fresh()->consolidated_at);
        $this->assertNotNull($cash2->fresh()->consolidated_at);

        // Target rows created with correct shape
        $expense1 = Expense::where('cash_expense_id', $cash1->id)->firstOrFail();
        $this->assertSame($hotel->id, $expense1->hotel_id);
        $this->assertSame('naqd', $expense1->payment_type);
        $this->assertSame($owner->id, $expense1->created_by);
        $this->assertSame($cash1->expense_category_id, $expense1->expense_category_id);
        $this->assertSame('2026-04-03', $expense1->expense_date->toDateString());
        $this->assertEquals(50000.00, (float) $expense1->amount);
        $this->assertStringContainsString('[Petty cash]', $expense1->name);
        $this->assertStringContainsString('Fuel', $expense1->name);
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

        $this->assertSame(3, $firstRun);
        $this->assertSame(0, $secondRun, 'Re-running consolidation must skip already-consolidated rows.');

        // Total expense rows still 3 — no duplication
        $this->assertSame(3, Expense::count());
    }

    public function test_converts_usd_rows_using_exchange_rate_service(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        // Pre-seed the cache so ExchangeRateService doesn't hit the network.
        // 1 USD = 12,500 UZS for this test.
        Cache::put('fx_usd_uzs_reference', [
            'rate' => 12500.00,
            'source' => 'cbu',
            'effective_date' => '2026-04-15',
            'fetched_at' => now()->toIso8601String(),
        ], 3600);

        $usdRow = CashExpense::factory()->create([
            'amount' => 25.00,
            'currency' => 'USD',
            'description' => 'Hardware store run',
            'occurred_at' => Carbon::parse('2026-04-12 11:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);
        $posted = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(1, $posted);

        $expense = Expense::where('cash_expense_id', $usdRow->id)->firstOrFail();
        // 25 USD × 12500 UZS = 312,500 UZS
        $this->assertEquals(312500.00, (float) $expense->amount);
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
        $posted = $service->consolidateMonth($period, $hotel->id, $owner->id);

        $this->assertSame(1, $posted, 'Only the non-rejected row should post.');
        $this->assertNull($rejected->fresh()->consolidated_at, 'Rejected row must remain unconsolidated.');
        $this->assertNotNull($approved->fresh()->consolidated_at);
        $this->assertSame(0, Expense::where('cash_expense_id', $rejected->id)->count());
        $this->assertSame(1, Expense::where('cash_expense_id', $approved->id)->count());
    }

    public function test_fx_rate_unavailable_rolls_back_whole_batch(): void
    {
        $hotel = $this->makeHotel();
        $owner = User::factory()->create();
        $period = Carbon::parse('2026-04-15');

        // Two UZS rows that would normally post, plus one USD row with no
        // FX rate available. All-or-nothing semantics: nothing should post.
        Cache::forget('fx_usd_uzs_reference');
        Cache::forget('fx_eur_uzs_reference');
        Cache::forget('fx_rub_uzs_reference');

        $this->app->bind(ExchangeRateService::class, function () {
            $fake = new class extends ExchangeRateService
            {
                public function getUsdToUzs(): ?array
                {
                    return null;
                }

                public function getEurToUzs(): ?array
                {
                    return null;
                }

                public function getRubToUzs(): ?array
                {
                    return null;
                }
            };

            return $fake;
        });

        $uzs1 = CashExpense::factory()->create([
            'amount' => 25000.00, 'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-02 10:00'),
        ]);
        $uzs2 = CashExpense::factory()->create([
            'amount' => 60000.00, 'currency' => 'UZS',
            'occurred_at' => Carbon::parse('2026-04-09 10:00'),
        ]);
        $usd = CashExpense::factory()->create([
            'amount' => 20.00, 'currency' => 'USD',
            'occurred_at' => Carbon::parse('2026-04-11 10:00'),
        ]);

        $service = app(ConsolidatePettyCashService::class);

        try {
            $service->consolidateMonth($period, $hotel->id, $owner->id);
            $this->fail('Expected DomainException when FX rate unavailable.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('USD rate unavailable', $e->getMessage());
        }

        // Nothing posted, nothing stamped — the whole transaction rolled back.
        $this->assertSame(0, Expense::count());
        $this->assertNull($uzs1->fresh()->consolidated_at);
        $this->assertNull($uzs2->fresh()->consolidated_at);
        $this->assertNull($usd->fresh()->consolidated_at);
    }

    /**
     * Build a fully-valid Hotel row. The hotels table has many NOT NULL
     * columns added by later migrations; supplying explicit values keeps
     * the test independent of unrelated schema drift.
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
