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
            $table->date('arrival_date')->nullable();
            $table->date('departure_date')->nullable();
            $table->string('guest_name');

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
