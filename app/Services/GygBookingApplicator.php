<?php

namespace App\Services;

use App\Models\GygInboundEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Apply parsed GYG inbound emails into the main booking domain tables.
 *
 * Responsibilities:
 * - Create/match guest records
 * - Create booking records (idempotent by booking_number)
 * - Link inbound email to created booking
 * - Mark email as 'applied'
 *
 * Does NOT handle cancellations or amendments (Phase 6).
 */
class GygBookingApplicator
{
    /**
     * Apply a single parsed new_booking email.
     *
     * @return array{applied: bool, booking_id: ?int, skipped_reason: ?string, error: ?string}
     */
    public function applyNewBooking(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;

        if (! $ref) {
            return ['applied' => false, 'booking_id' => null, 'skipped_reason' => null, 'error' => 'Missing gyg_booking_reference'];
        }

        // Idempotency: check if booking already exists
        $existingBooking = DB::table('bookings')
            ->where('booking_number', $ref)
            ->first();

        if ($existingBooking) {
            // Link email to existing booking and mark applied
            $email->update([
                'booking_id'        => $existingBooking->id,
                'processing_status' => 'applied',
                'applied_at'        => now(),
            ]);

            Log::info('GygBookingApplicator: idempotent skip — booking already exists', [
                'email_id'   => $email->id,
                'booking_id' => $existingBooking->id,
                'ref'        => $ref,
            ]);

            return ['applied' => true, 'booking_id' => $existingBooking->id, 'skipped_reason' => 'already_exists', 'error' => null];
        }

        // Wrap guest creation + booking creation in a transaction
        $bookingId = null;
        $error = null;

        DB::transaction(function () use ($email, $ref, &$bookingId) {
            // Step 1: Create or match guest
            $guestId = $this->findOrCreateGuest($email);

            // Step 2: Match tour (best effort)
            $tourId = $this->matchTour($email->tour_name, $email->option_title);

            // Step 3: Build booking_start_date_time
            $timeDefaulted = false;
            $startDateTime = $this->buildDateTime($email->travel_date, $email->travel_time, $timeDefaulted);

            // Step 4: Build special_requests with parsed metadata
            $specialRequests = $this->buildSpecialRequests($email, $timeDefaulted);

            // Step 5: Create booking
            // grand_total is int (whole dollars). GYG price is a decimal string like "458.00".
            $grandTotal = (int) round((float) ($email->price ?? 0));

            $bookingId = DB::table('bookings')->insertGetId([
                'booking_number'          => $ref,
                'guest_id'                => $guestId,
                'tour_id'                 => $tourId,
                'booking_start_date_time' => $startDateTime,
                'booking_status'          => 'confirmed',
                'booking_source'          => 'getyourguide',
                'grand_total'             => $grandTotal,
                'special_requests'        => $specialRequests,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            // Step 6: Link email to booking
            $email->update([
                'booking_id'        => $bookingId,
                'processing_status' => 'applied',
                'applied_at'        => now(),
            ]);
        });

        if ($bookingId) {
            Log::info('GygBookingApplicator: booking created', [
                'email_id'   => $email->id,
                'booking_id' => $bookingId,
                'ref'        => $ref,
                'guest'      => $email->guest_name,
                'date'       => $email->travel_date?->format('Y-m-d'),
            ]);
            return ['applied' => true, 'booking_id' => $bookingId, 'skipped_reason' => null, 'error' => null];
        }

        return ['applied' => false, 'booking_id' => null, 'skipped_reason' => null, 'error' => $error ?? 'Unknown error'];
    }

    /**
     * Apply a cancellation email to an existing booking.
     *
     * @return array{applied: bool, booking_id: ?int, skipped_reason: ?string, error: ?string}
     */
    public function applyCancellation(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;

        if (! $ref) {
            return ['applied' => false, 'booking_id' => null, 'skipped_reason' => null, 'error' => 'Missing gyg_booking_reference'];
        }

        $booking = DB::table('bookings')
            ->where('booking_number', $ref)
            ->first();

        if (! $booking) {
            // Booking not found — needs manual review
            $email->update([
                'processing_status' => 'needs_review',
                'apply_error'       => "Booking not found for cancellation: {$ref}",
            ]);

            Log::warning('GygBookingApplicator: cancellation target not found', [
                'email_id' => $email->id,
                'ref'      => $ref,
            ]);

            return ['applied' => false, 'booking_id' => null, 'skipped_reason' => null, 'error' => "Booking not found: {$ref}"];
        }

        // Idempotency: already cancelled
        if ($booking->booking_status === 'cancelled') {
            $email->update([
                'booking_id'        => $booking->id,
                'processing_status' => 'applied',
                'applied_at'        => now(),
            ]);

            return ['applied' => true, 'booking_id' => $booking->id, 'skipped_reason' => 'already_cancelled', 'error' => null];
        }

        // Valid transition: confirmed → cancelled
        DB::transaction(function () use ($booking, $email) {
            DB::table('bookings')
                ->where('id', $booking->id)
                ->update([
                    'booking_status' => 'cancelled',
                    'updated_at'     => now(),
                ]);

            $email->update([
                'booking_id'        => $booking->id,
                'processing_status' => 'applied',
                'applied_at'        => now(),
            ]);
        });

        Log::info('GygBookingApplicator: booking cancelled', [
            'email_id'   => $email->id,
            'booking_id' => $booking->id,
            'ref'        => $ref,
        ]);

        return ['applied' => true, 'booking_id' => $booking->id, 'skipped_reason' => null, 'error' => null];
    }

    /**
     * Handle an amendment email.
     *
     * Amendments are too variable for safe automatic application.
     * Strategy: link to booking if found, mark needs_review, notify owner.
     * The owner reviews and applies changes manually.
     *
     * @return array{applied: bool, booking_id: ?int, skipped_reason: ?string, error: ?string}
     */
    public function handleAmendment(GygInboundEmail $email): array
    {
        $ref = $email->gyg_booking_reference;

        if (! $ref) {
            return ['applied' => false, 'booking_id' => null, 'skipped_reason' => null, 'error' => 'Missing gyg_booking_reference'];
        }

        $booking = DB::table('bookings')
            ->where('booking_number', $ref)
            ->first();

        $email->update([
            'booking_id'        => $booking?->id,
            'processing_status' => 'needs_review',
            'apply_error'       => 'Amendment requires manual review — auto-apply not safe',
            'applied_at'        => now(),
        ]);

        Log::info('GygBookingApplicator: amendment marked for review', [
            'email_id'   => $email->id,
            'booking_id' => $booking?->id,
            'ref'        => $ref,
        ]);

        return [
            'applied'        => false,
            'booking_id'     => $booking?->id,
            'skipped_reason' => 'amendment_needs_review',
            'error'          => null,
        ];
    }

    // ── Guest matching/creation ──────────────────────────

    /**
     * Find or create a guest record.
     *
     * Matching rules (PM-approved, Phase 5.1):
     * - Match by phone only (strong identifier)
     * - Match by email only (strong identifier)
     * - Match by name + phone (strong)
     * - Match by name + email (strong)
     * - Do NOT match by name alone (risk of wrong linkage)
     *
     * If no strong match found, create a new guest.
     * If guest_name is absent, create with auditable synthetic name.
     */
    private function findOrCreateGuest(GygInboundEmail $email): int
    {
        $firstName = null;
        $lastName  = null;

        if ($email->guest_name) {
            $parts     = $this->splitName($email->guest_name);
            $firstName = $parts['first'];
            $lastName  = $parts['last'];

            // Strong match: phone (globally unique for travel bookings)
            if ($email->guest_phone) {
                $match = DB::table('guests')->where('phone', $email->guest_phone)->first();
                if ($match) return $match->id;
            }

            // Strong match: email
            if ($email->guest_email) {
                $match = DB::table('guests')->where('email', $email->guest_email)->first();
                if ($match) return $match->id;
            }

            // No strong identifier available — do NOT match by name alone.
            // Create a new guest to avoid wrong linkage.
        } else {
            // No guest name — auditable synthetic placeholder
            $firstName = 'GYG Guest';
            $lastName  = $email->gyg_booking_reference ?? 'Unknown';

            Log::info('GygBookingApplicator: creating synthetic guest (no name in email)', [
                'ref' => $email->gyg_booking_reference,
            ]);
        }

        return DB::table('guests')->insertGetId([
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'email'            => $email->guest_email ?? 'not-provided@gyg-import.local',
            'phone'            => $email->guest_phone ?? 'not-provided',
            'country'          => 'Unknown',
            'number_of_people' => $email->pax ?? 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Tour matching ───────────────────────────────────

    private function matchTour(?string $tourName, ?string $optionTitle = null): ?int
    {
        // Try matching against tourName first, then fall back to optionTitle.
        // The fallback handles cases where the parser mis-captured a subheader as
        // tourName (e.g. "Your offer has been booked:") while the real tour title
        // ended up in optionTitle.
        $candidates = array_filter([$tourName, $optionTitle]);

        foreach ($candidates as $candidate) {
            // Try exact title match
            $tour = DB::table('tours')->where('title', $candidate)->first();
            if ($tour) return $tour->id;

            // Try partial match (GYG titles are often longer than internal titles)
            $tour = DB::table('tours')
                ->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower(mb_substr($candidate, 0, 30)) . '%'])
                ->first();
            if ($tour) return $tour->id;

            // Try keyword matching for known tours
            $lower = strtolower($candidate);
            $keywordMap = [
                'yurt camp'  => '%yurt camp%',
                'shahrisabz' => '%shahrisabz%',
                'bukhara'    => '%bukhara%',
            ];

            foreach ($keywordMap as $keyword => $pattern) {
                if (str_contains($lower, $keyword)) {
                    $tour = DB::table('tours')->whereRaw('LOWER(title) LIKE ?', [$pattern])->first();
                    if ($tour) return $tour->id;
                }
            }
        }

        Log::warning('GygBookingApplicator: tour not matched', ['tour_name' => $tourName, 'option_title' => $optionTitle]);
        return null;
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Build booking_start_date_time from parsed date and time.
     * If time is missing, defaults to 09:00:00 and sets $timeDefaulted flag.
     */
    private function buildDateTime(mixed $date, ?string $time, bool &$timeDefaulted): ?string
    {
        $timeDefaulted = false;

        if (! $date) return null;

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : substr((string) $date, 0, 10);

        if ($time) {
            return $dateStr . ' ' . $time;
        }

        $timeDefaulted = true;
        return $dateStr . ' 09:00:00';
    }

    /**
     * Build structured special_requests string with all parsed metadata
     * that doesn't have a dedicated column in the bookings table.
     */
    private function buildSpecialRequests(GygInboundEmail $email, bool $timeDefaulted): string
    {
        $parts = [];

        $parts[] = "GYG Ref: {$email->gyg_booking_reference}";

        if ($email->option_title) {
            $parts[] = "Option: {$email->option_title}";
        }
        if ($email->tour_type) {
            $parts[] = "Type: {$email->tour_type} ({$email->tour_type_source})";
        }
        if ($email->guide_status) {
            $parts[] = "Guide: {$email->guide_status} ({$email->guide_status_source})";
        }
        if ($email->price) {
            $parts[] = "Price: {$email->currency} {$email->price}";
        }
        if ($email->language) {
            $parts[] = "Language: {$email->language}";
        }
        if ($email->pax) {
            $parts[] = "{$email->pax} Adults";
        }
        if ($timeDefaulted) {
            $parts[] = "Time: defaulted to 09:00 (not present in source email)";
        }

        return implode('. ', $parts);
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return [
            'first' => $parts[0] ?? '',
            'last'  => $parts[1] ?? '',
        ];
    }
}
