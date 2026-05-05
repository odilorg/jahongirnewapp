<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.6.1 — Two-level expense category taxonomy.
 *
 * 56 flat expense categories cause real bot UX pain (operators scroll
 * past 50 unused entries every expense). Adds parent_id self-FK so:
 *   - 9 top-level parents (Зарплаты, Налоги, Коммунальные, Финансы,
 *     Туры, Ремонт, Офис, Кухня, Прочее)
 *   - existing 56 categories become children of one of those parents
 *   - reports gain accounting-grade summaries (group-by parent)
 *
 * Doctrine — IDs are IMMUTABLE:
 *   - No row deleted, no row merged
 *   - All historical cash_expenses keep their original expense_category_id
 *   - The "Soliq" duplicate (id=11 + id=52) is intentionally preserved
 *     in this PR; merge is a separate phased-D task with safe
 *     reassignment, not a side-effect of taxonomy work.
 *
 * IDs of 9 new parent rows are anchored above the existing space
 * (>= 1000) so they never collide with legacy children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            // Self-referencing FK. Nullable so top-level parents can
            // exist with parent_id=null. nullOnDelete so deleting a
            // parent doesn't cascade-destroy all children — we'd
            // rather an admin manually re-parent.
            if (! Schema::hasColumn('expense_categories', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()
                    ->after('id')
                    ->constrained('expense_categories')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('expense_categories', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('name');
            }
            if (! Schema::hasColumn('expense_categories', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
            // Optional cleaner operator-facing label. Falls back to
            // `name` when null (legacy raw alias kept untouched).
            if (! Schema::hasColumn('expense_categories', 'display_name')) {
                $table->string('display_name', 100)->nullable()->after('name');
            }
            if (! Schema::hasColumn('expense_categories', 'slug')) {
                $table->string('slug', 64)->nullable()->unique()->after('id');
            }
        });

        // Seed 9 parent buckets at fixed IDs (>= 1000) so legacy
        // child IDs are guaranteed not to collide.
        $now = now();
        $parents = [
            ['id' => 1001, 'slug' => 'salaries',  'name' => 'Salaries',          'display_name' => 'Зарплаты',     'sort_order' => 10],
            ['id' => 1002, 'slug' => 'taxes',     'name' => 'Taxes',             'display_name' => 'Налоги',       'sort_order' => 20],
            ['id' => 1003, 'slug' => 'utilities', 'name' => 'Utilities',         'display_name' => 'Коммунальные', 'sort_order' => 30],
            ['id' => 1004, 'slug' => 'finance',   'name' => 'Finance',           'display_name' => 'Финансы',      'sort_order' => 40],
            ['id' => 1005, 'slug' => 'tour_ops',  'name' => 'Tour ops',          'display_name' => 'Туры',         'sort_order' => 50],
            ['id' => 1006, 'slug' => 'repairs',   'name' => 'Repairs',           'display_name' => 'Ремонт',       'sort_order' => 60],
            ['id' => 1007, 'slug' => 'office',    'name' => 'Office & supplies', 'display_name' => 'Офис',         'sort_order' => 70],
            ['id' => 1008, 'slug' => 'kitchen',   'name' => 'Kitchen',           'display_name' => 'Кухня',        'sort_order' => 80],
            ['id' => 1009, 'slug' => 'other',     'name' => 'Other',             'display_name' => 'Прочее',       'sort_order' => 99],
        ];
        foreach ($parents as &$p) {
            $p['parent_id']  = null;
            $p['is_active']  = true;
            $p['created_at'] = $now;
            $p['updated_at'] = $now;
        }
        unset($p);
        DB::table('expense_categories')->insert($parents);

        // Map every existing child (ids 1..56) to its operator-approved
        // parent. Any child not listed here stays parent_id=NULL — those
        // get flagged in the admin UI for reclassification.
        //
        // Mapping below is the version YOU approved on 2026-05-05.
        $childToParent = [
            // Salaries (1001) — staff salaries + person-attributed payouts
            7 => 1001, 41 => 1001, 42 => 1001, 9 => 1001,
            56 => 1001, 29 => 1001, 30 => 1001,

            // Taxes (1002) — incl. duplicate "Soliq" rows 11 and 52, kept separate
            11 => 1002, 12 => 1002, 13 => 1002, 17 => 1002, 18 => 1002,
            20 => 1002, 21 => 1002, 22 => 1002, 40 => 1002, 46 => 1002,
            47 => 1002, 48 => 1002, 52 => 1002,

            // Utilities (1003)
            10 => 1003, 14 => 1003, 15 => 1003, 24 => 1003, 25 => 1003,
            26 => 1003, 27 => 1003, 43 => 1003,

            // Finance (1004) — bank fees + credit obligations
            23 => 1004, 37 => 1004,

            // Tour ops (1005)
            8 => 1005, 31 => 1005, 32 => 1005, 33 => 1005, 34 => 1005,

            // Repairs (1006)
            6 => 1006, 44 => 1006, 45 => 1006, 49 => 1006, 50 => 1006, 51 => 1006,

            // Office & supplies (1007)
            38 => 1007, 39 => 1007, 53 => 1007, 54 => 1007, 55 => 1007,

            // Kitchen (1008)
            1 => 1008, 2 => 1008, 3 => 1008, 28 => 1008, 36 => 1008,

            // Other / Прочее (1009) — historically unclear labels stay safe here
            4 => 1009, 5 => 1009, 16 => 1009, 19 => 1009, 35 => 1009,
        ];

        foreach ($childToParent as $childId => $parentId) {
            DB::table('expense_categories')
                ->where('id', $childId)
                ->update([
                    'parent_id'  => $parentId,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Drop the 9 parent rows we inserted (anchored at id >= 1001
        // so the deletion is precise — children land back at parent
        // null but keep their original IDs).
        DB::table('expense_categories')->whereBetween('id', [1001, 1009])->delete();

        Schema::table('expense_categories', function (Blueprint $table) {
            if (Schema::hasColumn('expense_categories', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
            foreach (['display_name', 'slug', 'sort_order', 'is_active'] as $col) {
                if (Schema::hasColumn('expense_categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
