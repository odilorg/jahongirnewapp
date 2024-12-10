<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zayavkas', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('id'); // Tracks who created the resource
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by'); // Tracks who last updated the resource
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zayavkas', function (Blueprint $table) {
            //
        });
    }
};
