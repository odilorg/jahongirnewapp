<?php

declare(strict_types=1);

namespace App\Services\Gyg;

use App\Models\BookingInquiry;
use App\Models\GygInboundEmail;
use App\Services\BookingInquiryNotifier;
use App\Services\GygPickupResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single write path from GYG inbound emails into booking_inquiries.
 *
 * Three operations:
 *   createFromInboundEmail()       — new booking → confirmed inquiry
 *   cancelFromInboundEmail()       — cancellation → mark inquiry cancelled
 *   flagAmendmentForReview()       — amendment → append note, do NOT mutate
 *
 * Idempotency key: (source='gyg', external_reference=<GYG booking ref>).
 * All writes are transaction-wrapped. Notifications fire after commit.
 *
 * This service does NOT touch the legacy `bookings` or `guests` tables.
 */
class GygInquiryWriter
{
    public function __construct(
        private GygTourProductMatcher $matcher,
        private GygPickupResolver $pickupResolver,
        private BookingInquiryNotifier $notifier,
    ) {
    }

    /**
     * @return array{created: bool, inquiry_id: ?int, skipped_reason: ?string, error: ?string}
     */
    public function createFromInboundEmail(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;
        if (! $ref) {
            return ['created' => false, 'inquiry_id' => null, 'skipped_reason' => null, 'error' => 'Missing gyg_booking_reference'];
        }

        // Idempotency: check outside transaction first (fast path)
        $existing = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)
            ->where('external_reference', $ref)
            ->first();

        if ($existing) {
            // Link email to the existing inquiry if not already linked
            $email->update([
                'booking_inquiry_id' => $existing->id,
                'processing_status'  => 'applied',
                'applied_at'         => now(),
            ]);

            Log::info('GygInquiryWriter: idempotent skip — inquiry already exists', [
                'email_id'   => $email->id,
                'inquiry_id' => $existing->id,
                'ref'        => $ref,
            ]);

            return ['created' => false, 'inquiry_id' => $existing->id, 'skipped_reason' => 'already_exists', 'error' => null];
        }

        // Match tour product
        $match = $this->matcher->match($email->tour_name, $email->option_title);

        $inquiry = null;

        DB::transaction(function () use ($email, $ref, $match, &$inquiry) {
            // Second idempotency check inside transaction (race guard)
            $dup = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)
                ->where('external_reference', $ref)
                ->lockForUpdate()
                ->first();

            if ($dup) {
                $inquiry = $dup;

                return;
            }

            $inquiry = new BookingInquiry();
            $inquiry->reference              = BookingInquiry::generateReference();
            $inquiry->source                 = BookingInquiry::SOURCE_GYG;
            $inquiry->external_reference     = $ref;
            $inquiry->tour_slug              = $match['slug'];
            $inquiry->tour_name_snapshot     = $email->tour_name ?? $email->option_title ?? 'GYG Tour';
            $inquiry->tour_product_id        = $match['product_id'];
            $inquiry->tour_product_direction_id = $match['direction_id'];
            $inquiry->tour_type              = $match['tour_type'];
            $inquiry->customer_name          = $email->guest_name ?: 'GYG Guest';
            $inquiry->customer_email         = $email->guest_email ?? '';
            $inquiry->customer_phone         = $email->guest_phone ?? '';
            $inquiry->customer_country       = '';
            $inquiry->people_adults          = max(1, (int) ($email->pax ?? 1));
            $inquiry->people_children        = 0;
            $inquiry->travel_date            = $email->travel_date;
            $inquiry->pickup_time            = $email->travel_time;
            $inquiry->pickup_point           = $this->pickupResolver->resolveFromEmail($email);
            $inquiry->price_quoted           = $email->price;
            $inquiry->currency               = $email->currency ?: 'USD';

            // OTA commission — configurable per source in config/tour_export.php
            $gross = (float) ($email->price ?? 0);
            $commissionRate = (float) config('tour_export.ota_commission_rates.gyg', 30);
            $inquiry->commission_rate   = $commissionRate;
            $inquiry->commission_amount = round($gross * $commissionRate / 100, 2);
            $inquiry->net_revenue       = round($gross * (100 - $commissionRate) / 100, 2);
            $inquiry->payment_method         = BookingInquiry::PAYMENT_ONLINE;
            $inquiry->paid_at                = $email->email_date;
            $inquiry->status                 = BookingInquiry::STATUS_CONFIRMED;
            $inquiry->confirmed_at           = $email->email_date;
            $inquiry->prep_status            = BookingInquiry::PREP_NOT_PREPARED;
            $inquiry->submitted_at           = $email->email_date;
            $inquiry->internal_notes         = $this->buildCreateAuditNote($email, $match);
            $inquiry->save();

            $email->update([
                'booking_inquiry_id' => $inquiry->id,
                'processing_status'  => 'applied',
                'applied_at'         => now(),
            ]);

            // Phase 16.3 — GYG emails are pre-paid by definition. Record
            // as guest payment for audit/history. paid_at is already set
            // on the inquiry so the observer won't override it.
            if ((float) $inquiry->price_quoted > 0) {
                \App\Models\GuestPayment::create([
                    'booking_inquiry_id' => $inquiry->id,
                    'amount'             => (float) $inquiry->price_quoted,
                    'currency'           => 'USD',
                    'payment_type'       => 'full',
                    'payment_method'     => 'gyg',
                    'payment_date'       => $email->email_date?->toDateString() ?? now()->toDateString(),
                    'reference'          => $inquiry->external_reference,
                    'status'             => 'recorded',
                ]);
            }
        });

        if (! $inquiry) {
            return ['created' => false, 'inquiry_id' => null, 'skipped_reason' => null, 'error' => 'Transaction produced no inquiry'];
        }

        // Check if this was a race-condition duplicate (inquiry existed before our create)
        if ($inquiry->wasRecentlyCreated === false && $inquiry->source === BookingInquiry::SOURCE_GYG) {
            return ['created' => false, 'inquiry_id' => $inquiry->id, 'skipped_reason' => 'race_duplicate', 'error' => null];
        }

        // Notify AFTER commit
        try {
            $this->notifier->notify($inquiry->fresh());
        } catch (\Throwable $e) {
            Log::warning('GygInquiryWriter: notification failed (inquiry was created)', [
                'inquiry_id' => $inquiry->id,
                'error'      => $e->getMessage(),
            ]);
        }

        Log::info('GygInquiryWriter: inquiry created', [
            'email_id'   => $email->id,
            'inquiry_id' => $inquiry->id,
            'ref'        => $ref,
            'guest'      => $email->guest_name,
            'date'       => $email->travel_date?->format('Y-m-d'),
            'confidence' => $match['confidence'],
        ]);

        return ['created' => true, 'inquiry_id' => $inquiry->id, 'skipped_reason' => null, 'error' => null];
    }

    /**
     * @return array{cancelled: bool, inquiry_id: ?int, skipped_reason: ?string, error: ?string}
     */
    public function cancelFromInboundEmail(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;
        if (! $ref) {
            return ['cancelled' => false, 'inquiry_id' => null, 'skipped_reason' => null, 'error' => 'Missing gyg_booking_reference'];
        }

        $inquiry = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)
            ->where('external_reference', $ref)
            ->first();

        if (! $inquiry) {
            $email->update([
                'processing_status' => 'needs_review',
                'apply_error'       => "Cancellation target not found: {$ref}",
            ]);

            Log::warning('GygInquiryWriter: cancellation target not found', [
                'email_id' => $email->id,
                'ref'      => $ref,
            ]);

            return ['cancelled' => false, 'inquiry_id' => null, 'skipped_reason' => null, 'error' => "Inquiry not found for ref: {$ref}"];
        }

        // Idempotent: already cancelled
        if ($inquiry->status === BookingInquiry::STATUS_CANCELLED) {
            $email->update([
                'booking_inquiry_id' => $inquiry->id,
                'processing_status'  => 'applied',
                'applied_at'         => now(),
            ]);

            return ['cancelled' => true, 'inquiry_id' => $inquiry->id, 'skipped_reason' => 'already_cancelled', 'error' => null];
        }

        DB::transaction(function () use ($inquiry, $email) {
            $inquiry->update([
                'status'       => BookingInquiry::STATUS_CANCELLED,
                'cancelled_at' => $email->email_date ?? now(),
            ]);

            $this->appendInternalNote($inquiry, sprintf(
                'Cancelled via GYG email. Ref: %s. Original email date: %s.',
                $email->gyg_booking_reference,
                $email->email_date?->format('Y-m-d H:i') ?? 'unknown',
            ));

            $email->update([
                'booking_inquiry_id' => $inquiry->id,
                'processing_status'  => 'applied',
                'applied_at'         => now(),
            ]);
        });

        // Notify after commit — use dedicated cancellation message, not
        // the generic "🆕 new inquiry" template.
        try {
            $this->notifier->notifyCancelled($inquiry->fresh());
        } catch (\Throwable $e) {
            Log::warning('GygInquiryWriter: cancellation notification failed', [
                'inquiry_id' => $inquiry->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Phase 17 — notify assigned driver/guide if tour was already dispatched.
        // Idempotent via internal_notes marker so re-processing won't double-send.
        $this->notifySuppliersOfCancellation($inquiry->fresh());

        Log::info('GygInquiryWriter: inquiry cancelled', [
            'email_id'   => $email->id,
            'inquiry_id' => $inquiry->id,
            'ref'        => $ref,
        ]);

        return ['cancelled' => true, 'inquiry_id' => $inquiry->id, 'skipped_reason' => null, 'error' => null];
    }

    /**
     * @return array{flagged: bool, inquiry_id: ?int}
     */
    public function flagAmendmentForReview(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;
        if (! $ref) {
            return ['flagged' => false, 'inquiry_id' => null];
        }

        $inquiry = BookingInquiry::where('source', BookingInquiry::SOURCE_GYG)
            ->where('external_reference', $ref)
            ->first();

        DB::transaction(function () use ($inquiry, $email) {
            if ($inquiry) {
                $this->appendInternalNote($inquiry, sprintf(
                    'GYG amendment received — manual review required. '
                    . 'Parsed changes may include date/pax/option updates. '
                    . 'Email ID: %d. Subject: %s',
                    $email->id,
                    mb_substr($email->email_subject ?? '', 0, 100),
                ));
            }

            $email->update([
                'booking_inquiry_id' => $inquiry?->id,
                'processing_status'  => 'needs_review',
                'apply_error'        => 'Amendment requires manual review — auto-apply not safe',
                'applied_at'         => now(),
            ]);
        });

        // Notify after commit — use the correct amendment template, not
        // the "🆕 New website inquiry" generic message.
        if ($inquiry) {
            try {
                $this->notifier->notifyAmendmentReceived($inquiry->fresh());
            } catch (\Throwable $e) {
                Log::warning('GygInquiryWriter: amendment notification failed', [
                    'inquiry_id' => $inquiry->id,
                ]);
            }
        }

        Log::info('GygInquiryWriter: amendment flagged for review', [
            'email_id'   => $email->id,
            'inquiry_id' => $inquiry?->id,
            'ref'        => $ref,
        ]);

        return ['flagged' => true, 'inquiry_id' => $inquiry?->id];
    }

    // ── Audit note helpers ──────────────────────────────

    private function buildCreateAuditNote(GygInboundEmail $email, array $match): string
    {
        $timestamp = now()->format('Y-m-d H:i');
        $parts = [
            "[{$timestamp}] Auto-imported from GYG email.",
            "  Ref: {$email->gyg_booking_reference}",
        ];

        if ($email->option_title) {
            $parts[] = "  Option: {$email->option_title}";
        }

        $parts[] = "  Tour match: {$match['confidence']} (slug={$match['slug']}, source={$match['match_source']})";

        if ($match['tour_type']) {
            $parts[] = "  Type: {$match['tour_type']}";
        }
        if ($email->price) {
            $parts[] = "  Price: {$email->currency} {$email->price}";
        }
        if ($email->pax) {
            $parts[] = "  Pax: {$email->pax}";
        }
        if ($email->language) {
            $parts[] = "  Language: {$email->language}";
        }
        if ($email->guide_status) {
            $parts[] = "  Guide: {$email->guide_status} ({$email->guide_status_source})";
        }

        return implode("\n", $parts);
    }

    private function appendInternalNote(BookingInquiry $inquiry, string $note): void
    {
        $timestamp = now()->format('Y-m-d H:i');
        $existing  = $inquiry->internal_notes ?? '';
        $separator = $existing ? "\n" : '';

        $inquiry->update([
            'internal_notes' => $existing . $separator . "[{$timestamp}] {$note}",
        ]);
    }

    /**
     * Phase 17 — notify assigned driver/guide that a tour was cancelled.
     * Idempotent via internal_notes markers so re-processing the same
     * GYG cancellation email won't re-notify suppliers.
     *
     * Runs after commit. Failures are logged, never throw.
     */
    private function notifySuppliersOfCancellation(BookingInquiry $inquiry): void
    {
        $dispatcher = app(\App\Services\DriverDispatchNotifier::class);
        $notes      = (string) $inquiry->internal_notes;

        foreach (['driver', 'guide'] as $role) {
            $supplier = $role === 'driver' ? $inquiry->driver : $inquiry->guide;
            if (! $supplier) {
                continue;
            }

            $marker = ucfirst($role) . ' cancellation notification sent';
            if (str_contains($notes, $marker)) {
                continue; // already notified — idempotent
            }

            try {
                $result = $dispatcher->notifyCancellation($inquiry, $role);
                if ($result['ok'] ?? false) {
                    $this->appendInternalNote($inquiry, "{$marker} (msg_id={$result['msg_id']})");
                } else {
                    $this->appendInternalNote($inquiry, "⚠️ {$role} cancellation notification FAILED: " . ($result['reason'] ?? 'unknown'));
                }
            } catch (\Throwable $e) {
                Log::warning('GygInquiryWriter: supplier cancellation notify threw', [
                    'inquiry_id' => $inquiry->id,
                    'role'       => $role,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
