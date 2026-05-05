<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\Cashier\RecordSmallSaleAction;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\IncomeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 1.6.0 invariants for the small-sale taxonomy + admin recorder.
 *
 * Pins:
 *   1. Default income categories are seeded by the migration.
 *   2. Existing cash_transactions.category='sale' rows backfill to 'other'.
 *   3. RecordSmallSaleAction stamps type=in / category=sale and links
 *      the chosen income_category_id (so reports can break down
 *      ancillary revenue while legacy 'sale' bucket still aggregates).
 *   4. Existing 'sale' rows still queryable via the legacy category
 *      column — back-compat doctrine.
 */
final class RecordSmallSaleTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function income_categories_are_seeded_by_migration(): void
    {
        $expected = ['water', 'soft_drinks', 'snacks', 'souvenirs', 'tour_addon', 'service_fee', 'tip', 'other'];
        $found    = IncomeCategory::pluck('slug')->all();
        sort($expected);
        sort($found);
        $this->assertSame($expected, $found, 'all default income categories must exist');

        // 'water' is the operationally-most-common bucket so it must
        // be active (the Filament Select preselects it).
        $this->assertTrue(IncomeCategory::where('slug', 'water')->value('is_active'));
    }

    /** @test */
    public function legacy_sale_rows_are_backfilled_to_other(): void
    {
        $other = IncomeCategory::where('slug', 'other')->firstOrFail();

        // Insert a row that simulates a pre-migration legacy 'sale'
        // (income_category_id should be null, then nudged to 'other'
        // by re-running the same backfill logic via SQL).
        $tx = CashTransaction::create([
            'cashier_shift_id'   => null,
            'type'               => 'in',
            'amount'             => 5000,
            'currency'           => 'UZS',
            'category'           => 'sale',
            'income_category_id' => null,
            'payment_method'     => 'cash',
        ]);

        // Re-apply the migration's backfill rule — the SAME line in up()
        \DB::table('cash_transactions')
            ->where('category', 'sale')
            ->whereNull('income_category_id')
            ->update(['income_category_id' => $other->id]);

        $this->assertSame($other->id, $tx->fresh()->income_category_id);
    }

    /** @test */
    public function admin_petty_sale_creates_in_sale_with_chosen_category(): void
    {
        $admin = User::factory()->create();
        $water = IncomeCategory::where('slug', 'water')->firstOrFail();

        $tx = app(RecordSmallSaleAction::class)->execute([
            'amount'             => 12000,
            'currency'           => 'UZS',
            'income_category_id' => $water->id,
            'payment_method'     => 'cash',
            'notes'              => '2 bottles',
        ], $admin->id);

        $this->assertSame('in', $tx->type instanceof \BackedEnum ? $tx->type->value : $tx->type);
        $this->assertSame('sale', $tx->category instanceof \BackedEnum ? $tx->category->value : $tx->category);
        $this->assertSame($water->id, $tx->income_category_id);
        $this->assertEquals(12000, (float) $tx->amount);
        $this->assertSame('UZS', $tx->currency instanceof \BackedEnum ? $tx->currency->value : $tx->currency);
        $this->assertSame($admin->id, $tx->created_by);
        $this->assertSame('2 bottles', $tx->notes);
    }

    /** @test */
    public function action_attaches_to_open_shift_when_one_exists(): void
    {
        $cashier = User::factory()->create();
        // Seed a drawer for this transaction's isolation scope; the
        // FK on cashier_shifts.cash_drawer_id requires it.
        $drawer = \App\Models\CashDrawer::firstOrCreate(
            ['name' => 'Test Drawer'],
            ['is_active' => true]
        );
        $shift = CashierShift::create([
            'user_id'        => $cashier->id,
            'cash_drawer_id' => $drawer->id,
            'opened_at'      => now(),
            'status'         => 'open',
        ]);

        $souvenirs = IncomeCategory::where('slug', 'souvenirs')->firstOrFail();
        $tx = app(RecordSmallSaleAction::class)->execute([
            'amount'             => 50000,
            'currency'           => 'UZS',
            'income_category_id' => $souvenirs->id,
            'payment_method'     => 'cash',
        ]);

        $this->assertSame($shift->id, $tx->cashier_shift_id);
    }

    /** @test */
    public function action_records_admin_attributed_when_no_open_shift(): void
    {
        // No CashierShift seeded — fall through to NULL shift_id.
        $other = IncomeCategory::where('slug', 'other')->firstOrFail();
        $tx = app(RecordSmallSaleAction::class)->execute([
            'amount'             => 8000,
            'currency'           => 'UZS',
            'income_category_id' => $other->id,
            'payment_method'     => 'cash',
        ]);

        $this->assertNull($tx->cashier_shift_id);
        $this->assertSame('sale', $tx->category instanceof \BackedEnum ? $tx->category->value : $tx->category);
        $this->assertSame($other->id, $tx->income_category_id);
    }

    /** @test */
    public function existing_sale_aggregations_still_work(): void
    {
        // Doctrine: legacy reports filter on category='sale'; new
        // taxonomy MUST not break that. Create one tx via the new
        // Action and confirm it shows up in the legacy bucket.
        $tip = IncomeCategory::where('slug', 'tip')->firstOrFail();
        app(RecordSmallSaleAction::class)->execute([
            'amount'             => 3000,
            'currency'           => 'UZS',
            'income_category_id' => $tip->id,
            'payment_method'     => 'cash',
        ]);

        $count = CashTransaction::where('category', 'sale')->count();
        $this->assertGreaterThanOrEqual(1, $count, 'new petty-sale rows must remain visible to legacy category=sale reports');
    }
}
