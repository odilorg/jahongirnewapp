<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds24_payment_syncs', function (Blueprint $table) {
            $table->id();

            // One sync row per local cash transaction. Unique ensures no double-syncs.
            $table->foreignId('cash_transaction_id')
                  ->unique()
                  ->constrained('cash_transactions')
                  ->cascadeOnDelete();

            $table->string('beds24_booking_id')->index();

            // The UUID embedded in the Beds24 payment description as [ref:UUID].
            // This is the idempotency anchor for webhook reconciliation.
            $table->uuid('local_reference')->unique()->index();

            // Returned by Beds24 API after successful push
            $table->string('beds24_payment_id')->nullable();

            // USD amount pushed to Beds24
            $table->decimal('amount_usd', 10, 2);

            // Beds24SyncStatus state machine
            $table->string('status', 20)->default('pending');

            $table->unsignedSmallInteger('push_attempts')->default(0);
            $table->timestamp('last_push_at')->nullable();
            $table->text('last_error')->nullable();

            // Set when webhook returns with matching local_reference
            $table->timestamp('webhook_confirmed_at')->nullable();
            $table->jsonb('webhook_raw_payload')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'created_at']); // for nightly reconciliation query
        });

        // Now that beds24_payment_syncs exists, add the FK from cash_transactions
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreign('beds24_payment_sync_id')
                  ->references('id')
                  ->on('beds24_payment_syncs')
                  ->nullOnDelete();
        });

        // Also add the FK from fx_manager_approvals.used_in_cash_transaction_id
        Schema::table('fx_manager_approvals', function (Blueprint $table) {
            $table->foreign('used_in_cash_transaction_id')
                  ->references('id')
                  ->on('cash_transactions')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fx_manager_approvals', function (Blueprint $table) {
            $table->dropForeign(['used_in_cash_transaction_id']);
        });
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['beds24_payment_sync_id']);
        });
        Schema::dropIfExists('beds24_payment_syncs');
    }
};
