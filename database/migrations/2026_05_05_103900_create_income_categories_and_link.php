<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Income taxonomy for petty sales (water, snacks, souvenirs, etc).
 *
 * Doctrine: keep cash_transactions.category as the broad bucket
 * ('sale' / 'cash_in' / 'exchange' / etc.) and add income_category_id
 * as a finer-grained taxonomy ON TOP. Existing reports keep working
 * untouched; new reports can split by income category when desired.
 *
 * Backfill: every existing row with category='sale' gets pointed at
 * 'other' so historical data stays valid and queryable. Operators
 * can re-categorise via admin if they want, but nothing is orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed default taxonomy. Order is deliberate — most-common
        // first so the Filament Select preselects 'water' on render.
        $defaults = [
            ['slug' => 'water',        'name' => 'Water',           'sort_order' => 10],
            ['slug' => 'soft_drinks',  'name' => 'Soft drinks',     'sort_order' => 20],
            ['slug' => 'snacks',       'name' => 'Snacks',          'sort_order' => 30],
            ['slug' => 'souvenirs',    'name' => 'Souvenirs',       'sort_order' => 40],
            ['slug' => 'tour_addon',   'name' => 'Tour add-on',     'sort_order' => 50],
            ['slug' => 'service_fee',  'name' => 'Service fee',     'sort_order' => 60],
            ['slug' => 'tip',          'name' => 'Tip',             'sort_order' => 70],
            ['slug' => 'other',        'name' => 'Other',           'sort_order' => 99],
        ];
        $now = now();
        foreach ($defaults as &$row) {
            $row['is_active']  = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);
        DB::table('income_categories')->insert($defaults);

        // Add nullable FK on cash_transactions. Nullable because most
        // rows are not petty sales (guest payments, exchanges, expenses).
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreignId('income_category_id')->nullable()
                ->after('category')
                ->constrained('income_categories')
                ->nullOnDelete();
        });

        // Backfill: existing 'sale' rows → 'other' so reporting joins
        // never produce nulls on legacy data.
        $otherId = (int) DB::table('income_categories')->where('slug', 'other')->value('id');
        if ($otherId > 0) {
            DB::table('cash_transactions')
                ->where('category', 'sale')
                ->whereNull('income_category_id')
                ->update(['income_category_id' => $otherId]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['income_category_id']);
            $table->dropColumn('income_category_id');
        });
        Schema::dropIfExists('income_categories');
    }
};
