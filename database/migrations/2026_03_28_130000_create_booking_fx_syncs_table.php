<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Beds24 booking — upserted each time FX amounts are pushed.
 *
 * This is the source-of-truth for what was written to Beds24 infoItems.
 * Both the print flow and the cashier bot read from this table.
 * Neither re-derives amounts independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_fx_syncs', function (Blueprint $table) {
            $table->id();

            // External Beds24 booking ID (matches beds24_booking_id on beds24_bookings)
            // Unique: one live sync row per booking
            $table->string('beds24_booking_id')->unique()->index();

            // Rate snapshot used for this push
            $table->date('fx_rate_date');
            $table->foreignId('daily_exchange_rate_id')->constrained('daily_exchange_rates');

            // Inputs used at time of push — stored for change detection (staleness)
            $table->decimal('usd_amount_used', 10, 2);
            $table->date('arrival_date_used');

            // Published amounts (whole-unit: UZS rounds to 10,000, EUR to 1, RUB to 100)
            $table->integer('uzs_final');
            $table->integer('eur_final');
            $table->integer('rub_final');

            // Push state
            $table->enum('push_status', ['pushed', 'failed', 'pending'])->default('pending');
            $table->timestamp('fx_last_pushed_at')->nullable();
            $table->text('last_push_error')->nullable();
            $table->unsignedTinyInteger('push_attempts')->default(0);

            // Operational metadata
            // last_source_trigger: honest name — upserted row, only shows most recent trigger
            $table->enum('last_source_trigger', ['webhook', 'print', 'repair_job', 'bot', 'manual']);
            $table->timestamp('last_print_prepared_at')->nullable();

            // Bump this constant in config/fx.php when infoItem codes change.
            // Any sync row with a lower version will be considered stale.
            $table->unsignedSmallInteger('infoitems_version')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_fx_syncs');
    }
};
