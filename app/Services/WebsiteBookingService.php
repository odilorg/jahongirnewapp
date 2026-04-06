<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for bookings that originate from the website contact form.
 *
 * Responsibilities:
 *  1. Resolve the tour (fail-closed if unknown — booking is not created)
 *  2. Guard against duplicate submissions via idempotency_key
 *  3. Find or create the guest record by email
 *  4. Create the booking with safe defaults for required NOT-NULL columns
 *
 * The Booking model's boot() hook schedules WA/Telegram notifications
 * automatically after creation — this service does not trigger them directly.
 *
 * ── Identity model ──────────────────────────────────────────────────────────
 * Guests are de-duplicated by normalised email address only. This is a
 * deliberate trade-off:
 *   - PRO: returning guests get a unified booking history without extra UI
 *   - CON: shared/family/company emails will appear as one guest
 *
 * Implication: we never update an existing guest's name or phone on a new
 * booking — only the missing-guest (create) path touches those fields.
 * The submission-time contact snapshot is always preserved in special_requests
 * so operators can see exactly what was submitted, regardless of guest record state.
 *
 * If identity requirements grow (e.g. passport matching, CRM sync), migrate
 * to a proper contact/identity model at that point rather than pre-optimising now.
 */
class WebsiteBookingService
{
    /**
     * Keyword → partial tour title patterns, ordered most-specific first.
     * Mirrors the logic in GygBookingApplicator::matchTour() so both sources
     * resolve to the same internal tours.
     *
     * When the website moves to canonical tour identifiers (e.g. hidden select
     * values mapped to tour IDs), this keyword map can be removed entirely.
     */
    private const TOUR_KEYWORD_MAP = [
        'shahrisabz driver'  => '%shahrisabz%driver%',
        'shahrisabz guide'   => '%shahrisabz%guide%',
        'shahrisabz'         => '%shahrisabz%',
        'yurt camp private'  => '%yurt camp private%',
        'yurt camp group'    => '%yurt camp group%',
        'yurt camp'          => '%yurt camp%',
        'bukhara'            => '%bukhara%',
    ];

    /**
     * Create a booking from a validated website form submission.
     *
     * @param  array{
     *   tour: string,
     *   name: string,
     *   email: string,
     *   phone: string,
     *   hotel: string|null,
     *   date: string,
     *   adults: int,
     *   children: int,
     *   tour_code: string|null,
     * } $data  Sanitised payload from WebsiteBookingRequest::toBookingData()
     *
     * @return array{booking: Booking, created: bool}
     *   `created` is false when the request is a duplicate (idempotent replay).
     *
     * @throws \RuntimeException if the tour cannot be resolved (fail-closed).
     */
    public function createFromWebsite(array $data): array
    {
        // ── 1. Resolve tour first — fail-closed ──────────────────────────────
        // We resolve before the idempotency check so that a genuinely unresolvable
        // tour name is never silently turned into a null-product booking.
        // If this throws, the caller (controller) returns 500 to mailer-tours.php,
        // which discards it silently. The admin email was already sent, so the
        // operator still has the submission and can create the booking manually.
        $tourId = $this->resolveTourId($data['tour'], $data['tour_code']);

        // ── 2. Idempotency check ─────────────────────────────────────────────
        // Hash includes adults + children so the same person can legitimately
        // make two separate bookings on the same day for different group sizes
        // (e.g. adds children in a follow-up submission).
        $idempotencyKey = $this->buildIdempotencyKey($data);

        $existing = Booking::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            Log::info('WebsiteBookingService: duplicate submission — returning existing booking', [
                'booking_id'     => $existing->id,
                'booking_number' => $existing->booking_number,
                'email'          => $data['email'],
                'date'           => $data['date'],
            ]);

            return ['booking' => $existing, 'created' => false];
        }

        // ── 3. Find or create guest + booking inside a transaction ───────────
        return DB::transaction(function () use ($data, $tourId, $idempotencyKey) {
            $guest = $this->findOrCreateGuest($data);

            $booking = Booking::create([
                'guest_id'                => $guest->id,
                'tour_id'                 => $tourId,
                'booking_start_date_time' => $data['date'] . ' 09:00:00',
                'pickup_location'         => $data['hotel'],   // null → WA hotel-request flow
                'dropoff_location'        => 'TBD',
                'booking_source'          => 'website',
                'booking_status'          => 'pending',        // awaiting operator confirmation
                'payment_status'          => 'pending',
                'payment_method'          => 'pending',
                'grand_total'             => 0,                // operator sets after confirming price
                'amount'                  => 0,
                'group_name'              => $guest->first_name . ' ' . $guest->last_name,
                'driver_id'               => 0,
                'guide_id'                => 0,
                'special_requests'        => $this->buildSpecialRequests($data),
                'idempotency_key'         => $idempotencyKey,
            ]);

            Log::info('WebsiteBookingService: booking created', [
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'guest_id'       => $guest->id,
                'tour_id'        => $tourId,
                'date'           => $data['date'],
                'pax'            => $data['adults'] + $data['children'],
                'source'         => 'website',
            ]);

            return ['booking' => $booking, 'created' => true];
        });
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * SHA-256 hash of: normalised email | normalised tour | date | adults | children.
     *
     * Including pax counts prevents the same guest from being blocked from
     * booking the same tour on the same date with a different group size
     * (e.g. first books solo, then realises and re-submits for 2 adults).
     * Those are two distinct intended bookings, not a duplicate.
     */
    private function buildIdempotencyKey(array $data): string
    {
        $raw = implode('|', [
            mb_strtolower(trim($data['email'])),
            mb_strtolower(trim($data['tour'])),
            $data['date'],
            (string) $data['adults'],
            (string) $data['children'],
        ]);

        return hash('sha256', $raw);
    }

    /**
     * Resolve an internal tour_id from the free-text name submitted by the form.
     *
     * Behaviour is FAIL-CLOSED: throws RuntimeException if no tour is matched.
     * This prevents a booking from being created with a null/wrong product,
     * which would be worse than no booking at all (operator would never know
     * which tour to run).
     *
     * Resolution order (most deterministic → least deterministic):
     *  1. Exact title match
     *  2. tour_code prefix match (future: website form sends hidden tour ID)
     *  3. Keyword map (most-specific keyword first)
     *  4. 30-char prefix match (fallback for long GYG-style names)
     *
     * Upgrade path: once the website form submits a canonical tour identifier
     * (hidden select field, slug, or integer ID), step 1 becomes sufficient
     * and the keyword map can be deleted.
     */
    private function resolveTourId(string $tourName, ?string $tourCode): int
    {
        // 1. Exact title match
        $tour = DB::table('tours')->where('title', $tourName)->first();
        if ($tour) {
            return $tour->id;
        }

        // 2. tour_code prefix match
        if ($tourCode) {
            $tour = DB::table('tours')
                ->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($tourCode) . '%'])
                ->first();
            if ($tour) {
                return $tour->id;
            }
        }

        // 3. Keyword map — ordered most-specific first to avoid "yurt camp" matching
        //    "yurt camp private" before it can be checked.
        $lower = mb_strtolower($tourName);
        foreach (self::TOUR_KEYWORD_MAP as $keyword => $pattern) {
            if (str_contains($lower, $keyword)) {
                $tour = DB::table('tours')->whereRaw('LOWER(title) LIKE ?', [$pattern])->first();
                if ($tour) {
                    return $tour->id;
                }
            }
        }

        // 4. 30-char prefix fallback (same heuristic as GygBookingApplicator)
        $tour = DB::table('tours')
            ->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower(mb_substr($tourName, 0, 30)) . '%'])
            ->first();
        if ($tour) {
            return $tour->id;
        }

        // Fail-closed: log prominently so operator can action manually.
        // The admin email was already sent by mailer-tours.php, so no data is lost.
        Log::error('WebsiteBookingService: tour resolution failed — booking NOT created', [
            'tour_submitted' => $tourName,
            'tour_code'      => $tourCode,
            'action_required' => 'Create booking manually from admin email notification.',
        ]);

        throw new \RuntimeException(
            "Tour '{$tourName}' could not be matched to an internal tour. Booking not created."
        );
    }

    /**
     * Find an existing guest by normalised email, or create a new one.
     *
     * We use firstOrCreate (not updateOrCreate) deliberately:
     * - We never overwrite a returning guest's existing name/phone with new form data.
     *   A returning guest may have updated their details in the system; we don't
     *   want a new form submission to clobber that.
     * - The submission-time snapshot (name, phone, hotel, pax) is stored in
     *   special_requests on the booking, giving operators the exact data submitted
     *   regardless of what the guest record looks like.
     *
     * Limitation: multiple real people sharing an email (family, company) will
     * map to one guest record. Acceptable for current volume; revisit if CRM
     * identity requirements grow.
     */
    private function findOrCreateGuest(array $data): Guest
    {
        $nameParts = $this->splitName($data['name']);

        return Guest::firstOrCreate(
            ['email' => $data['email']],
            [
                'first_name'       => $nameParts['first'],
                'last_name'        => $nameParts['last'],
                'phone'            => $data['phone'],
                'number_of_people' => $data['adults'] + $data['children'],
                'country'          => '',   // not captured at form time; operator fills later
            ]
        );
    }

    /**
     * Build the special_requests string for the booking.
     *
     * This serves as the authoritative submission-time snapshot:
     * pax breakdown, hotel (if provided), and phone — so operators always have
     * the exact data submitted even if the guest record has since been updated.
     */
    private function buildSpecialRequests(array $data): string
    {
        $parts = ["Adults: {$data['adults']}"];

        if ($data['children'] > 0) {
            $parts[] = "Children: {$data['children']}";
        }

        if ($data['hotel']) {
            $parts[] = "Hotel: {$data['hotel']}";
        }

        $parts[] = "Phone: {$data['phone']}";
        $parts[] = "Tour submitted: {$data['tour']}";

        return implode(' | ', $parts);
    }

    /**
     * Split a single full-name string into first + last.
     * Everything after the first space becomes last name.
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [
            'first' => $parts[0],
            'last'  => $parts[1] ?? '',
        ];
    }
}
