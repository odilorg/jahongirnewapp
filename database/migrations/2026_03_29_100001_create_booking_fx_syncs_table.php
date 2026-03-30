<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_fx_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id')->unique();

            // Rate snapshot
            $table->date('fx_rate_date');
            $table->date('printed_rate_date')->nullable();
            // References the exchange_rates table (the project's existing rate store).
            // Nullable so rows can be created before a rate is confirmed.
            $table->foreignId('exchange_rate_id')
                  ->nullable()
                  ->constrained('exchange_rates')
                  ->nullOnDelete();

            // Booking context at calculation time
            $table->decimal('usd_amount_used', 10, 2);
            $table->date('arrival_date_used');

            // Pre-calculated presentable amounts
            $table->unsignedBigInteger('uzs_final');
            $table->decimal('eur_final', 10, 2);
            $table->decimal('rub_final', 10, 2);
            $table->decimal('usd_final', 10, 2);

            // Beds24 infoItems push tracking
            $table->string('push_status', 20)->default('pending'); // FxSyncPushStatus
            $table->timestamp('fx_last_pushed_at')->nullable();
            $table->text('last_push_error')->nullable();
            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->string('last_source_trigger', 20)->nullable(); // FxSourceTrigger

            // Print snapshot tracking
            $table->timestamp('last_print_prepared_at')->nullable();
            $table->unsignedSmallInteger('infoitems_version')->default(1);

            $table->timestamps();

            $table->index('beds24_booking_id');
            $table->index('push_status');
            $table->index('fx_rate_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_fx_syncs');
    }
};
