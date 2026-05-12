<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR job-application form follow-up (Phase 1.1, 2026-05-11).
 *
 * Adds three additive fields requested after the form went live —
 * HR needs to know:
 *  1. is the candidate currently employed?  (checkbox)
 *  2. is the candidate currently studying?  (checkbox)
 *  3. which parts of the day can they work?  (multi-select checkboxes)
 *
 * Use case driver: many young candidates attend classes 8:00–13:00,
 * so HR needs to call them outside those hours AND can only schedule
 * shifts during their free slots.
 *
 * All additive + nullable so historical rows from before this
 * migration stay valid. Booleans default false to match the
 * "unchecked checkbox" semantic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_candidates', function (Blueprint $table) {
            $table->boolean('is_currently_working')
                ->default(false)
                ->after('previous_workplace_text');

            $table->boolean('is_currently_studying')
                ->default(false)
                ->after('is_currently_working');

            // Multi-select: array of slot values like ['morning', 'evening'].
            // Empty array (or null) means the candidate didn't pick any —
            // FormRequest enforces ≥1 selection at intake, but historical
            // rows from before this migration are NULL and that's valid.
            $table->json('availability_slots')
                ->nullable()
                ->after('is_currently_studying');
        });
    }

    public function down(): void
    {
        Schema::table('job_candidates', function (Blueprint $table) {
            $table->dropColumn([
                'is_currently_working',
                'is_currently_studying',
                'availability_slots',
            ]);
        });
    }
};
