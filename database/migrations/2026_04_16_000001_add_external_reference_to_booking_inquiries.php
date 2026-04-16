<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4 — OTA sourcing.
 *
 * Adds a nullable external_reference column for storing the OTA's own
 * booking ID (GYG ref, Viator ref, Booking.com confirmation code, etc.).
 * Indexed for operator "find by GYG ID" lookups. Not unique — duplicate
 * IDs from operator re-entry are soft-warned, not hard-failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('external_reference', 64)
                ->nullable()
                ->after('source')
                ->comment('OTA booking ID — GYG, Viator, etc. NULL for direct leads.');
            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex(['external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
