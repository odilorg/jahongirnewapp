<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.5 — Link GYG inbound emails to the live booking_inquiries table.
 *
 * The old `booking_id` column pointed to the legacy `bookings` table (dead).
 * This migration adds `booking_inquiry_id` as the new FK without dropping
 * the old column (preserving audit history for already-applied rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_inquiry_id')
                ->nullable()
                ->after('booking_id');

            $table->foreign('booking_inquiry_id')
                ->references('id')
                ->on('booking_inquiries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gyg_inbound_emails', function (Blueprint $table) {
            $table->dropForeign(['booking_inquiry_id']);
            $table->dropColumn('booking_inquiry_id');
        });
    }
};
