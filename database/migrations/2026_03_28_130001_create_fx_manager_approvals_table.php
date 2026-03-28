<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists manager approval requests for cashier FX payment overrides.
 *
 * Created when a cashier's proposed payment amount differs from the printed
 * amount by more than the cashier self-approval threshold (config fx.override_policy).
 *
 * Lifecycle: pending → approved|rejected → consumed (when payment is recorded).
 * A consumed row cannot be reused — the back-link to cash_transaction_id is the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_manager_approvals', function (Blueprint $table) {
            $table->id();

            // Context
            $table->string('beds24_booking_id')->index();
            $table->string('bot_session_id')->index();  // Telegram chat_id:message_id or UUID
            $table->foreignId('cashier_id')->constrained('users');

            // What the cashier proposed
            $table->string('currency', 3);
            $table->decimal('amount_presented', 12, 2);   // what bot showed (from sync)
            $table->decimal('amount_proposed', 12, 2);    // what cashier wants to record
            $table->decimal('variance_pct', 6, 2);        // computed at creation

            // Approval state
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'consumed'])
                  ->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Expiry — manager must respond within this window
            $table->timestamp('expires_at');

            // Back-link: set atomically when payment is recorded using this approval
            // Proves exactly which transaction consumed this approval — cannot be reused
            $table->foreignId('used_in_cash_transaction_id')
                  ->nullable()
                  ->constrained('cash_transactions');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_manager_approvals');
    }
};
