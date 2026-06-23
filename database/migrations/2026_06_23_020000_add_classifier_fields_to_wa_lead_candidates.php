<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2b — classifier result fields on wa_lead_candidates. Additive only.
 * not_lead_subtype is the load-bearing field: ONLY spam|b2b|supplier may ever be
 * auto-dismissed; accommodation|logistics|personal|other go to review (real
 * non-tour people, never silently dropped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_lead_candidates', function (Blueprint $table): void {
            $table->text('first_messages')->nullable()->after('first_inbound'); // first few inbound, for classifier
            $table->string('classification', 32)->nullable()->after('status');  // genuine_tour_inquiry|not_lead|uncertain
            $table->string('not_lead_subtype', 24)->nullable()->after('classification');
            $table->decimal('confidence', 3, 2)->nullable()->after('not_lead_subtype');
            $table->text('reason')->nullable()->after('confidence');
            $table->string('detected_tour')->nullable()->after('reason');
            $table->date('detected_date')->nullable()->after('detected_tour');
            $table->unsignedSmallInteger('detected_party_size')->nullable()->after('detected_date');
            $table->string('language', 8)->nullable()->after('detected_party_size');
            $table->boolean('needs_review')->default(true)->after('language');
            $table->string('decision', 24)->nullable()->after('needs_review'); // would_auto_create|would_auto_dismiss|would_review
            $table->string('dismissed_reason')->nullable()->after('decision');
            $table->timestamp('classified_at')->nullable()->after('dismissed_reason');
        });
    }

    public function down(): void
    {
        Schema::table('wa_lead_candidates', function (Blueprint $table): void {
            $table->dropColumn([
                'first_messages', 'classification', 'not_lead_subtype', 'confidence', 'reason',
                'detected_tour', 'detected_date', 'detected_party_size', 'language',
                'needs_review', 'decision', 'dismissed_reason', 'classified_at',
            ]);
        });
    }
};
