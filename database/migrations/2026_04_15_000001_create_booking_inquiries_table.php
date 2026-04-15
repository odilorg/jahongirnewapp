<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website booking inquiries — leads coming in from the public jahongir-travel.uz
 * form. Deliberately DECOUPLED from the existing `bookings`/`tours` tables so
 * the legacy Beds24/GYG/operator flows cannot break this pipeline (and vice versa).
 *
 * An inquiry is NOT a confirmed booking. It is a lead that an operator must
 * contact, qualify, and either convert to a real booking or mark cancelled/spam.
 *
 * Snapshot fields (tour_name_snapshot, page_url) are stored verbatim from the
 * submission so the record remains meaningful even if the website slug/title
 * changes later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_inquiries', function (Blueprint $table) {
            $table->id();

            // Human-readable reference for operator communication (INQ-2026-000123)
            $table->string('reference', 32)->unique();

            // Where this came from — extensible: 'website', 'telegram', 'manual', 'gyg'
            $table->string('source', 32)->default('website')->index();

            // Tour snapshot — no FK, immune to future catalogue changes
            $table->string('tour_slug', 191)->nullable()->index();
            $table->string('tour_name_snapshot', 255);
            $table->string('page_url', 500)->nullable();

            // Customer contact
            $table->string('customer_name', 191);
            $table->string('customer_email', 191)->index();
            $table->string('customer_phone', 64);
            $table->string('preferred_contact', 32)->nullable();

            // Trip details
            $table->unsignedSmallInteger('people_adults')->default(1);
            $table->unsignedSmallInteger('people_children')->default(0);
            $table->date('travel_date')->nullable()->index();
            $table->boolean('flexible_dates')->default(false);
            $table->text('message')->nullable();

            // Operator workflow
            $table->string('status', 32)->default('new')->index();
            // new | contacted | awaiting_customer | confirmed | cancelled | spam
            $table->text('internal_notes')->nullable();

            // Timestamped state transitions — more reliable than status-only
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Request provenance (for spam tracing + support)
            $table->timestamp('submitted_at')->useCurrent();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_inquiries');
    }
};
