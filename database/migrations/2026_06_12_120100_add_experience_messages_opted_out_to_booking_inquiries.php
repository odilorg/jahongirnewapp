<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 29 — per-booking opt-out for the guest experience engine.
 *
 * When true, no experience messages are materialized or sent for the
 * booking. Operator-settable (Filament toggle). Keeps the data instead of
 * deleting rows so the opt-out is auditable and reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->boolean('experience_messages_opted_out')
                ->default(false)
                ->after('has_occasion_flag');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('experience_messages_opted_out');
        });
    }
};
