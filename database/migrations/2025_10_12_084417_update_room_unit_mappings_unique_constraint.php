<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_unit_mappings', function (Blueprint $table) {
            // Drop the old unique constraint on unit_name
            $table->dropUnique(['unit_name']);
            
            // Add composite unique constraint on unit_name + property_id
            $table->unique(['unit_name', 'property_id'], 'unit_property_unique');
        });
    }

    public function down(): void
    {
        Schema::table('room_unit_mappings', function (Blueprint $table) {
            // Drop the composite unique constraint
            $table->dropUnique('unit_property_unique');
            
            // Restore the old unique constraint
            $table->unique('unit_name');
        });
    }
};
