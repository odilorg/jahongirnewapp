<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15.1 — Operator attribution on booking inquiries.
 *
 * Three distinct roles:
 *   created_by  — who entered the lead (null = system: GYG email, website form, WA intake)
 *   assigned_to — who is currently working this lead (set on first meaningful action)
 *   closed_by   — who actually converted it (set on payment success)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropForeign(['closed_by_user_id']);
            $table->dropColumn(['created_by_user_id', 'assigned_to_user_id', 'closed_by_user_id']);
        });
    }
};
