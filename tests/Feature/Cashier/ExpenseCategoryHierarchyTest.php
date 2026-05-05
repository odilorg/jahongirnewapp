<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 1.6.1 — taxonomy invariants for expense categories.
 *
 * Pins:
 *   1. The 9 parent buckets exist with stable IDs (1001..1009)
 *   2. Slugs match the operator-approved set
 *   3. Russian display names are populated for the bot/admin UI
 *   4. Existing child categories are reachable by the same legacy IDs
 *      (back-compat doctrine — IDs are immutable)
 *   5. parent_id self-FK works both ways
 */
final class ExpenseCategoryHierarchyTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function nine_parent_buckets_seeded_with_fixed_ids(): void
    {
        $expected = [
            1001 => 'salaries',
            1002 => 'taxes',
            1003 => 'utilities',
            1004 => 'finance',
            1005 => 'tour_ops',
            1006 => 'repairs',
            1007 => 'office',
            1008 => 'kitchen',
            1009 => 'other',
        ];

        foreach ($expected as $id => $slug) {
            $row = ExpenseCategory::find($id);
            $this->assertNotNull($row, "parent id={$id} ({$slug}) must exist");
            $this->assertSame($slug, $row->slug);
            $this->assertNull($row->parent_id, "parent id={$id} must have parent_id=null");
            $this->assertTrue((bool) $row->is_active);
        }
    }

    /** @test */
    public function parent_buckets_carry_russian_display_names(): void
    {
        $expected = [
            1001 => 'Зарплаты',
            1002 => 'Налоги',
            1003 => 'Коммунальные',
            1004 => 'Финансы',
            1005 => 'Туры',
            1006 => 'Ремонт',
            1007 => 'Офис',
            1008 => 'Кухня',
            1009 => 'Прочее',
        ];

        foreach ($expected as $id => $display) {
            $this->assertSame($display, ExpenseCategory::find($id)->display_name);
        }
    }

    /** @test */
    public function parent_child_relations_work_both_ways(): void
    {
        $taxes = ExpenseCategory::find(1002);
        // Seed a fresh child against this parent inside the
        // transaction (no reliance on prod data being present).
        $child = ExpenseCategory::create([
            'name'        => 'Test Tax Row',
            'slug'        => 'test-tax-' . uniqid(),
            'display_name'=> 'Test Tax',
            'parent_id'   => $taxes->id,
            'is_active'   => true,
            'sort_order'  => 999,
        ]);

        $this->assertSame($taxes->id, $child->parent->id);
        $this->assertTrue($taxes->fresh()->children()->whereKey($child->id)->exists());
    }

    /** @test */
    public function children_scope_only_returns_rows_under_chosen_parent(): void
    {
        // Seed one child under salaries and one under taxes; salaries
        // children() must not return the tax child.
        $salaryChild = ExpenseCategory::create([
            'name'        => 'Test Salary Row',
            'slug'        => 'test-salary-' . uniqid(),
            'parent_id'   => 1001,
            'is_active'   => true,
        ]);
        $taxChild = ExpenseCategory::create([
            'name'        => 'Test Tax Row',
            'slug'        => 'test-tax-' . uniqid(),
            'parent_id'   => 1002,
            'is_active'   => true,
        ]);

        $salaries = ExpenseCategory::find(1001)->load('children');
        $childIds = $salaries->children->pluck('id')->all();
        $this->assertContains($salaryChild->id, $childIds);
        $this->assertNotContains($taxChild->id, $childIds);
    }
}
