<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR job-candidate intake table (Phase 1, 2026-05-11).
 *
 * Backs the public application form at `/jobs/apply` and the Filament
 * HR review surface (JobCandidateResource). One row per submitted
 * application. No update to existing tables; entirely additive.
 *
 * # Schema rationale
 *
 * - Common fields are real columns (indexed, queryable).
 * - The single per-position question lives in `position_answers` (JSON)
 *   so adding a new role (osh cook variant, night-driver, etc.)
 *   doesn't require a schema change. Today's vocabulary is one key per
 *   role; tomorrow's may be richer — the JSON shape accommodates
 *   either.
 * - HR workflow columns (status, assigned_to, etc.) are nullable on
 *   create — set by HR after intake.
 * - Audit columns (submitted_ip, submitted_user_agent) help diagnose
 *   spam waves and bot traffic; not displayed in Filament unless
 *   debugging.
 * - Soft-delete so a "withdrew" candidate can be recovered later if
 *   they reapply (common pattern in UZ market — same person reapplies
 *   in different season).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_candidates', function (Blueprint $table) {
            $table->id();

            // Contact (required at intake)
            $table->string('full_name', 255);
            $table->string('phone', 32);
            $table->string('whatsapp_phone', 32)->nullable();
            $table->unsignedTinyInteger('age');
            $table->string('city', 64);

            // Position & source
            $table->string('position', 32);                 // enum-validated at app layer
            $table->string('source', 32)->default('direct'); // olx | telegram | referral | walk_in | direct | other
            $table->string('source_reference', 255)->nullable(); // OLX listing URL etc.

            // Compensation & availability
            $table->unsignedInteger('expected_salary_uzs');
            $table->date('available_from')->nullable();
            $table->boolean('can_work_weekends');
            $table->boolean('can_work_nights');

            // Background
            $table->string('experience_level', 16);  // enum: none | less_than_1y | 1_to_3y | more_than_3y
            $table->string('previous_workplace_text', 500)->nullable();

            // Languages — fixed three (UZ/RU/EN) covers all UZ candidates today;
            // additional languages can be added in position_answers JSON if needed
            $table->string('uzbek_level', 8);    // no | basic | good | fluent
            $table->string('russian_level', 8);
            $table->string('english_level', 8);

            // Position-specific answer (single question for v1, JSON for flexibility)
            $table->json('position_answers')->nullable();

            // Optional file (private storage path)
            $table->string('cv_path', 512)->nullable();

            // HR workflow (filled after intake by HR staff)
            $table->string('status', 32)->default('new');
            // new | contacted | phone_screened | interview_scheduled |
            // interviewed | offered | hired | rejected | withdrew
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('interview_scheduled_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('interviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('internal_rating')->nullable(); // 1-5
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            // Spam / audit columns
            $table->string('submitted_ip', 64)->nullable();
            $table->string('submitted_user_agent', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            // - (phone, position) supports dedup query at submit time
            // - (status, position) supports the HR list-view default sort
            // - (created_at desc) supports "newest first" landing view
            $table->index(['phone', 'position'], 'job_candidates_dedup_idx');
            $table->index(['status', 'position'], 'job_candidates_status_idx');
            $table->index('source', 'job_candidates_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_candidates');
    }
};
