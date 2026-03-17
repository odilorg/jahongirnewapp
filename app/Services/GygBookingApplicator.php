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
            $tourId = $this->matchTour($email->tour_name);

            // Step 3: Build booking_start_date_time
            $startDateTime = $this->buildDateTime($email->travel_date, $email->travel_time);

            // Step 4: Build special_requests with parsed metadata
            $specialRequests = $this->buildSpecialRequests($email);

            // Step 5: Create booking
            $bookingId = DB::table('bookings')->insertGetId([
                'booking_number'          => $ref,
                'guest_id'                => $guestId,
                'tour_id'                 => $tourId,
                'booking_start_date_time' => $startDateTime,
                'booking_status'          => 'confirmed',
                'booking_source'          => 'getyourguide',
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

    // ── Guest matching/creation ──────────────────────────

    private function findOrCreateGuest(GygInboundEmail $email): int
    {
        $firstName = null;
        $lastName  = null;

        if ($email->guest_name) {
            $parts     = $this->splitName($email->guest_name);
            $firstName = $parts['first'];
            $lastName  = $parts['last'];

            // Try to match existing guest by name + phone/email
            $query = DB::table('guests')
                ->where('first_name', $firstName)
                ->where('last_name', $lastName);

            if ($email->guest_phone) {
                $match = (clone $query)->where('phone', $email->guest_phone)->first();
                if ($match) return $match->id;
            }
            if ($email->guest_email) {
                $match = (clone $query)->where('email', $email->guest_email)->first();
                if ($match) return $match->id;
            }

            // No match by name+contact — check name-only to avoid near-duplicates
            $nameMatch = $query->first();
            if ($nameMatch) return $nameMatch->id;
        } else {
            // No guest name — use auditable placeholder
            $firstName = 'GYG Guest';
            $lastName  = $email->gyg_booking_reference ?? 'Unknown';
        }

        return DB::table('guests')->insertGetId([
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'email'            => $email->guest_email,
            'phone'            => $email->guest_phone,
            'number_of_people' => $email->pax ?? 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Tour matching ───────────────────────────────────

    private function matchTour(?string $tourName): ?int
    {
        if (! $tourName) return null;

        // Try exact title match
        $tour = DB::table('tours')->where('title', $tourName)->first();
        if ($tour) return $tour->id;

        // Try partial match (GYG titles are often longer than internal titles)
        $tour = DB::table('tours')
            ->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower(mb_substr($tourName, 0, 30)) . '%'])
            ->first();
        if ($tour) return $tour->id;

        // Try keyword matching for known tours
        $lower = strtolower($tourName);
        $keywordMap = [
            'yurt camp'    => '%yurt camp%',
            'shahrisabz'   => '%shahrisabz%',
            'bukhara'      => '%bukhara%',
        ];

        foreach ($keywordMap as $keyword => $pattern) {
            if (str_contains($lower, $keyword)) {
                $tour = DB::table('tours')->whereRaw('LOWER(title) LIKE ?', [$pattern])->first();
                if ($tour) return $tour->id;
            }
        }

        Log::warning('GygBookingApplicator: tour not matched', ['tour_name' => $tourName]);
        return null;
    }

    // ── Helpers ─────────────────────────────────────────

    private function buildDateTime(?string $date, ?string $time): ?string
    {
        if (! $date) return null;

        $dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
        $timeStr = $time ?? '09:00:00';

        return $dateStr . ' ' . $timeStr;
    }

    /**
     * Build structured special_requests string with all parsed metadata
     * that doesn't have a dedicated column in the bookings table.
     */
    private function buildSpecialRequests(GygInboundEmail $email): string
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
