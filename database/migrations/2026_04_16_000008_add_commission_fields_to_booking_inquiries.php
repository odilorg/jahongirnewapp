<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — OTA commission accounting.
 *
 * price_quoted stays as the gross guest-facing price.
 * These three new fields track what the operator actually receives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('currency')
                ->comment('OTA commission percentage, e.g. 30.00 for GYG');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('commission_rate')
                ->comment('Actual platform cut in USD');
            $table->decimal('net_revenue', 10, 2)->nullable()->after('commission_amount')
                ->comment('What operator receives = price_quoted - commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount', 'net_revenue']);
        });
    }
};
