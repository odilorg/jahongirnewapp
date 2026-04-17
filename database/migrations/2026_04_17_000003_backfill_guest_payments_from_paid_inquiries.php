<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16.2 — backfill existing paid bookings into guest_payments.
 *
 * Every paid booking gets one historical payment row so the ledger
 * is consistent from day one. recorded_by = null (system/historical).
 */
return new class extends Migration
{
    public function up(): void
    {
        $paid = DB::table('booking_inquiries')
            ->whereNotNull('paid_at')
            ->where(function ($q) {
                $q->whereNotNull('price_quoted')->where('price_quoted', '>', 0);
            })
            ->get(['id', 'price_quoted', 'paid_at', 'payment_method', 'source', 'external_reference']);

        foreach ($paid as $b) {
            // Skip if already has a payment (idempotent re-run safe)
            $exists = DB::table('guest_payments')
                ->where('booking_inquiry_id', $b->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $method = match ($b->source) {
                'gyg'     => 'gyg',
                default   => match ($b->payment_method) {
                    'online'         => 'octo',
                    'cash_on_site'   => 'cash',
                    'card_office'    => 'card_office',
                    'bank_transfer'  => 'bank_transfer',
                    default          => 'other',
                },
            };

            DB::table('guest_payments')->insert([
                'booking_inquiry_id'  => $b->id,
                'amount'              => $b->price_quoted,
                'currency'            => 'USD',
                'payment_type'        => 'full',
                'payment_method'      => $method,
                'payment_date'        => \Carbon\Carbon::parse($b->paid_at)->toDateString(),
                'reference'           => $b->external_reference ?: null,
                'notes'               => 'Backfilled from paid_at on migration',
                'recorded_by_user_id' => null,
                'status'              => 'recorded',
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('guest_payments')
            ->where('notes', 'Backfilled from paid_at on migration')
            ->delete();
    }
};
