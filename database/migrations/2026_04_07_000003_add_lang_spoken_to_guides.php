<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add lang_spoken (JSON) to the guides table.
 *
 * This column was added directly to the production database without a migration
 * file, so it already exists in production but is absent from the test database.
 * The hasColumn guard makes this migration safe to re-run on production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('guides', 'lang_spoken')) {
            Schema::table('guides', function (Blueprint $table) {
                $table->json('lang_spoken')->nullable()->after('phone02');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('guides', 'lang_spoken')) {
            Schema::table('guides', function (Blueprint $table) {
                $table->dropColumn('lang_spoken');
            });
        }
    }
};
