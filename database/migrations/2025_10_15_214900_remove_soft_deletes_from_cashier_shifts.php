<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, hard delete any soft-deleted records
        DB::statement('DELETE FROM cashier_shifts WHERE deleted_at IS NOT NULL');
        
        // Remove the deleted_at column
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        
        // The unique constraint already exists, so we're good
        // It will now work properly without soft deletes interfering
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back soft deletes
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};


