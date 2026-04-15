<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.5 — make customer_email nullable.
 *
 * The public website form (POST /api/v1/inquiries) still requires email
 * via FormRequest validation, so website submissions are unchanged.
 *
 * The change is for manual admin entries: when an operator creates an
 * inquiry from a WhatsApp / phone / walk-in lead, they often have a
 * phone number but no email. Forcing them to type a fake one pollutes
 * the data and trains operators to bypass the form. Allow null.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('customer_email', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting requires backfilling rows where email is null first.
        // Left as a manual operation.
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->string('customer_email', 191)->nullable(false)->change();
        });
    }
};
