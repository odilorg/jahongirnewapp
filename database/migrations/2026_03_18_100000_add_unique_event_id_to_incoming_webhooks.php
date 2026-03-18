<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            // Unique constraint on event_id prevents duplicate processing
            // of the same Telegram update_id per bot. NULL values are
            // excluded from unique checks in MySQL.
            $table->unique('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
        });
    }
};
