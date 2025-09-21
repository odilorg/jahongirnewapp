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
        // Remove currency from cashier_shifts table
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        // Add currency to cash_transactions table
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('currency', 3)->default('UZS')->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add currency back to cashier_shifts table
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->string('currency', 3)->default('UZS')->after('beginning_saldo');
        });

        // Remove currency from cash_transactions table
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};