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
        // driver_id was added by an earlier migration (2024_08_30_084455).
        // Guard each column individually so this migration is idempotent
        // when run from scratch on a fresh database (e.g. SQLite in tests).
        if (!Schema::hasColumn('ratings', 'guide_id')) {
            Schema::table('ratings', function (Blueprint $table) {
                $table->foreignId('guide_id')->nullable()->constrained('guides')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('ratings', 'driver_id')) {
            Schema::table('ratings', function (Blueprint $table) {
                $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('ratings', 'booking_id')) {
            Schema::table('ratings', function (Blueprint $table) {
                $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            //
        });
    }
};
