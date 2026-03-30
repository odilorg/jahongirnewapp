<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily exchange rates set once per morning by CalculateAndPushDailyPaymentOptionsJob.
     *
     * Stores both CBU reference rates AND the pre-computed effective rates so
     * every day's audit snapshot is fully self-contained — no need to re-derive
     * the effective rate after the fact.
     *
     * Conversion chain:
     *   uzs_exact      = usd_amount × usd_uzs_rate
     *   eur_final      = ceil(uzs_exact / eur_effective_rate, eur_rounding_increment)
     *   rub_final      = ceil(uzs_exact / rub_effective_rate, rub_rounding_increment)
     *   uzs_final      = ceil(uzs_exact, uzs_rounding_increment)
     *
     * Effective rates:
     *   eur_effective_rate = eur_uzs_cbu_rate - eur_margin
     *   rub_effective_rate = rub_uzs_cbu_rate - rub_margin
     */
    public function up(): void
    {
        Schema::create('daily_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date')->unique(); // One row per calendar day

            // USD/UZS — primary rate
            $table->decimal('usd_uzs_rate', 15, 4)->comment('CBU or admin-confirmed USD→UZS rate');

            // EUR — CBU rate, safety margin, and derived effective rate
            $table->decimal('eur_uzs_cbu_rate', 15, 4)->comment('CBU EUR/UZS rate');
            $table->unsignedInteger('eur_margin')->default(200)->comment('Subtracted from CBU EUR rate (bank spread buffer)');
            $table->decimal('eur_effective_rate', 15, 4)->comment('eur_uzs_cbu_rate - eur_margin');

            // RUB — CBU rate, safety margin, and derived effective rate
            $table->decimal('rub_uzs_cbu_rate', 15, 4)->comment('CBU RUB/UZS rate');
            $table->unsignedInteger('rub_margin')->default(20)->comment('Subtracted from CBU RUB rate (bank spread buffer)');
            $table->decimal('rub_effective_rate', 15, 4)->comment('rub_uzs_cbu_rate - rub_margin');

            // Rounding increments — stored so historical rows reflect the rules used that day
            $table->unsignedInteger('uzs_rounding_increment')->default(10000);
            $table->unsignedInteger('eur_rounding_increment')->default(1);
            $table->unsignedInteger('rub_rounding_increment')->default(100);

            // Audit
            $table->unsignedBigInteger('set_by_user_id')->nullable()->comment('User who triggered the run; null = scheduled auto-run');
            $table->string('source', 20)->default('cbu')->comment('Rate source: cbu | er_api | floatrates | manual');
            $table->timestamp('fetched_at')->nullable()->comment('When rates were fetched from external API');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_exchange_rates');
    }
};
