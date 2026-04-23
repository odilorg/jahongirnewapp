<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gyg_products', function (Blueprint $table) {
            $table->unsignedInteger('min_participants')->default(1)->after('default_cutoff_seconds');
            $table->unsignedInteger('max_participants')->nullable()->after('min_participants');
        });

        // Set max=10 for T-619840 (matches test configuration in GYG portal)
        DB::table('gyg_products')
            ->where('gyg_product_id', 'T-619840')
            ->update(['min_participants' => 1, 'max_participants' => 10]);
    }

    public function down(): void
    {
        Schema::table('gyg_products', function (Blueprint $table) {
            $table->dropColumn(['min_participants', 'max_participants']);
        });
    }
};
