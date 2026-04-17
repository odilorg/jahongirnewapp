<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — Operator reminder table.
 *
 * Human-memory layer: "remind me on X date about booking Y".
 * Operator-owned, not system-driven. Keeps status/audit so
 * completed reminders stay visible for history, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_inquiry_id')
                ->constrained('booking_inquiries')
                ->cascadeOnDelete();
            $table->dateTime('remind_at')->index();
            $table->text('message');
            $table->foreignId('created_by_user_id')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')
                ->comment('pending | done | dismissed');
            $table->timestamp('notified_at')->nullable()
                ->comment('Set once when email sent — prevents re-sending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->index(['assigned_to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_reminders');
    }
};
