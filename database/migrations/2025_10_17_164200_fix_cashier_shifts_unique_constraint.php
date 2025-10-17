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
        // Check if the unique constraint exists and drop it
        $indexes = DB::select("SHOW INDEX FROM cashier_shifts WHERE Key_name = 'unique_open_shift_per_drawer_user'");
        
        if (!empty($indexes)) {
            Schema::table('cashier_shifts', function (Blueprint $table) {
                $table->dropUnique('unique_open_shift_per_drawer_user');
            });
        }

        // Check if the column already exists
        $columns = DB::select("SHOW COLUMNS FROM cashier_shifts WHERE Field = 'open_shift_marker'");
        
        if (empty($columns)) {
            // Add a virtual column that's only populated when status is 'open'
            // NULL values are ignored in unique constraints, so this effectively
            // creates a partial unique index for open shifts only
            DB::statement("
                ALTER TABLE cashier_shifts 
                ADD COLUMN open_shift_marker VARCHAR(50) 
                GENERATED ALWAYS AS (
                    CASE 
                        WHEN status = 'open' THEN CONCAT(cash_drawer_id, '-', user_id)
                        ELSE NULL 
                    END
                ) STORED
            ");
        }

        // Create unique constraint on the marker column if it doesn't exist
        $markerIndexes = DB::select("SHOW INDEX FROM cashier_shifts WHERE Key_name = 'unique_open_shift_per_drawer_user'");
        
        if (empty($markerIndexes)) {
            Schema::table('cashier_shifts', function (Blueprint $table) {
                $table->unique('open_shift_marker', 'unique_open_shift_per_drawer_user');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropUnique('unique_open_shift_per_drawer_user');
            $table->dropColumn('open_shift_marker');
        });

        // Restore the original (problematic) constraint
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->unique(['cash_drawer_id', 'user_id', 'status'], 'unique_open_shift_per_drawer_user');
        });
    }
};

