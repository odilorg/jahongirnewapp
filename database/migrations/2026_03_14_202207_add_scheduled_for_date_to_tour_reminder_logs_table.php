<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->date('scheduled_for_date')->nullable()->after('channel')
                ->comment('The actual tour date this reminder was for (idempotency key)');
        });
    }

    public function down(): void
    {
        Schema::table('tour_reminder_logs', function (Blueprint $table) {
            $table->dropColumn('scheduled_for_date');
        });
    }
};
