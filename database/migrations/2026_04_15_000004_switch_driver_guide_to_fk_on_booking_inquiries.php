<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.1 — replace free-text driver/guide names with FKs to the existing
 * drivers/guides catalog tables.
 *
 * The drivers/guides tables already exist, have nullable booking_id
 * (circular FK was dropped in an earlier migration), and are the normal
 * place for supplier data in this app. Using them keeps operator data
 * consistent across future modules.
 *
 * We drop the free-text columns because they never carried real data
 * (only two test inquiries existed when this was added).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('driver_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('drivers')
                ->nullOnDelete();

            $table->foreignId('guide_id')
                ->nullable()
                ->after('driver_id')
                ->constrained('guides')
                ->nullOnDelete();

            $table->dropColumn(['assigned_driver_name', 'assigned_guide_name']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('assigned_driver_name', 191)->nullable()->after('payment_method');
            $table->string('assigned_guide_name', 191)->nullable()->after('assigned_driver_name');

            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('guide_id');
        });
    }
};
