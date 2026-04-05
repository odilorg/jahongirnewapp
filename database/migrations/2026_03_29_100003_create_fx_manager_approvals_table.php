<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the earlier version created by 2026_03_28_130001 (different schema).
        Schema::dropIfExists('fx_manager_approvals');

        Schema::create('fx_manager_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('beds24_booking_id')->index();
            $table->string('bot_session_id', 120)->index();

            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_notified_id')->nullable()->constrained('users')->nullOnDelete();

            // What the cashier proposed
            $table->string('currency', 5);
            $table->decimal('amount_presented', 12, 2);
            $table->decimal('amount_proposed', 12, 2);
            $table->decimal('variance_pct', 5, 2);

            // Lifecycle
            $table->string('status', 20)->default('pending'); // ManagerApprovalStatus
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('expires_at');

            // Atomic consumption — set in the same DB transaction as the cash_transactions insert
            // Nullable FK to cash_transactions: we can't declare it as a proper FK here because
            // cash_transactions already exists. We add the FK constraint in migration 5.
            $table->unsignedBigInteger('used_in_cash_transaction_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['beds24_booking_id', 'status']);
        });

        // Now that fx_manager_approvals exists, add the FK from cash_transactions
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->foreign('override_approval_id')
                  ->references('id')
                  ->on('fx_manager_approvals')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['override_approval_id']);
        });
        Schema::dropIfExists('fx_manager_approvals');
    }
};
