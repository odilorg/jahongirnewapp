<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: add payment tracking fields to booking_inquiries.
 *
 * Deliberately keeps all payment state on the inquiry itself rather than
 * creating a separate payments table. Reasons:
 *  - Inquiries are the source of truth for website-originated sales.
 *  - One-to-one with payment in v1 (no partial payments, no refunds yet).
 *  - Avoids another table during the early shape-finding phase.
 *
 * `booking_id` is added NULLABLE for a future InquiryToBookingConverter
 * flow. It is not used today. Don't delete it just because it is empty —
 * it is the hook for Phase 6.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            // Money — stored verbatim from operator input. Currency is a
            // soft default; we only accept USD in v1.
            $table->decimal('price_quoted', 10, 2)->nullable()->after('message');
            $table->char('currency', 3)->default('USD')->after('price_quoted');

            // Payment method — 'online' | 'cash' | 'card_office' | null
            $table->string('payment_method', 32)->nullable()->after('currency');

            // Online payment trail
            $table->text('payment_link')->nullable()->after('payment_method');
            $table->timestamp('payment_link_sent_at')->nullable()->after('payment_link');
            $table->timestamp('paid_at')->nullable()->after('payment_link_sent_at');

            // Octo reference — indexed for fast webhook lookup (primary key
            // for inquiry-path callbacks).
            $table->string('octo_transaction_id', 128)->nullable()->after('paid_at');
            $table->index('octo_transaction_id');

            // Future conversion hook to the legacy bookings table. Nullable
            // and unused in Phase 4 — exists so Phase 6 doesn't need a
            // schema change.
            $table->unsignedBigInteger('booking_id')->nullable()->after('octo_transaction_id');
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $table->dropIndex(['octo_transaction_id']);
            $table->dropIndex(['booking_id']);
            $table->dropColumn([
                'price_quoted',
                'currency',
                'payment_method',
                'payment_link',
                'payment_link_sent_at',
                'paid_at',
                'octo_transaction_id',
                'booking_id',
            ]);
        });
    }
};
