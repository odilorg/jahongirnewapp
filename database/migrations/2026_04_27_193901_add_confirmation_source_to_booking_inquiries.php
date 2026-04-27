<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->enum('confirmation_source', ['manual', 'ota', 'offline', 'system'])
                ->nullable()
                ->after('status');
        });

        // Backfill: any inquiry already in a confirmed/awaiting_payment state
        // that came from a known OTA source (gyg, viator) is operationally
        // ota-confirmed. Website / phone / unknown sources stay NULL — they
        // pre-date the column and we don't want to invent history.
        DB::table('booking_inquiries')
            ->whereIn('source', ['gyg', 'viator'])
            ->whereNotNull('confirmed_at')
            ->update(['confirmation_source' => 'ota']);
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropColumn('confirmation_source');
        });
    }
};
