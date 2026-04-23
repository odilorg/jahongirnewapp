<?php

namespace App\Services;

use App\Models\GygAvailability;
use App\Models\GygBooking;
use App\Models\GygNotification;
use App\Models\GygProduct;
use App\Models\GygReservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GygService
{
    // Valid ticket categories per GYG spec
    const VALID_CATEGORIES = [
        'ADULT', 'CHILD', 'YOUTH', 'INFANT',
        'SENIOR', 'STUDENT', 'GROUP', 'COLLECTIVE',
    ];

    const RESERVATION_HOLD_MINUTES = 60;

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    /**
     * Return availabilities for a product between fromDateTime and toDateTime.
     */
    public function getAvailabilities(string $productId, string $from, string $to): array
    {
        $product = GygProduct::where('gyg_product_id', $productId)->first();

        if (! $product) {
            return $this->error('INVALID_PRODUCT', 'Product not found: ' . $productId);
        }

        if (! $product->is_active) {
            return $this->error('INVALID_PRODUCT', 'Product is not active: ' . $productId);
        }

        try {
            $fromDt = Carbon::parse($from);
            $toDt   = Carbon::parse($to);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILURE', 'Invalid datetime format');
        }

        $rows = GygAvailability::where('gyg_product_id', $productId)
            ->whereBetween('slot_datetime', [$fromDt, $toDt])
            ->orderBy('slot_datetime')
            ->get();

        $availabilities = $rows->map(function (GygAvailability $row) {
            $entry = [
                'dateTime'         => Carbon::parse($row->slot_datetime)->toIso8601String(),
                'productId'        => $row->gyg_product_id,
                'vacancies'        => $row->vacancies,
                'cutoffSeconds'    => $row->cutoff_seconds,
                'currency'         => $row->currency,
                'pricesByCategory' => [
                    'retailPrices' => $row->prices_by_category ?? [],
                ],
            ];

            if (! empty($row->opening_times)) {
                $entry['openingTimes'] = $row->opening_times;
            }

            return $entry;
        })->values()->all();

        return ['data' => ['availabilities' => $availabilities]];
    }

    // -------------------------------------------------------------------------
    // Reserve
    // -------------------------------------------------------------------------

    /**
     * Create a temporary hold for a product slot.
     */
    public function reserve(array $data): array
    {
        $productId          = $data['productId'] ?? null;
        $dateTime           = $data['dateTime'] ?? null;
        $bookingItems       = $data['bookingItems'] ?? [];
        $gygBookingRef      = $data['gygBookingReference'] ?? null;

        if (! $productId || ! $dateTime || empty($bookingItems) || ! $gygBookingRef) {
            return $this->error('VALIDATION_FAILURE', 'Missing required fields: productId, dateTime, bookingItems, gygBookingReference');
        }

        $product = GygProduct::where('gyg_product_id', $productId)->first();
        if (! $product) {
            return $this->error('INVALID_PRODUCT', 'Product not found: ' . $productId);
        }

        // Validate participant count against product min/max before checking vacancies
        $totalRequested  = collect($bookingItems)->sum('count');
        $minParticipants = $product->min_participants ?? 1;
        $maxParticipants = $product->max_participants;

        if ($totalRequested < $minParticipants) {
            return [
                'errorCode'               => 'INVALID_PARTICIPANTS_CONFIGURATION',
                'errorMessage'            => "The activity requires a minimum of {$minParticipants} participants",
                'participantsConfiguration' => ['min' => $minParticipants, 'max' => $maxParticipants],
            ];
        }
        if ($maxParticipants !== null && $totalRequested > $maxParticipants) {
            return [
                'errorCode'               => 'INVALID_PARTICIPANTS_CONFIGURATION',
                'errorMessage'            => "The activity cannot be reserved for more than {$maxParticipants} participants",
                'participantsConfiguration' => ['min' => $minParticipants, 'max' => $maxParticipants],
            ];
        }

        // Validate categories against this product's supported categories only
        $supportedCategories = collect($product->price_categories ?? [])->pluck('category')->toArray();
        foreach ($bookingItems as $item) {
            if (! in_array($item['category'] ?? '', $supportedCategories)) {
                return $this->error('INVALID_TICKET_CATEGORY', 'Category not supported by this product: ' . ($item['category'] ?? 'null'));
            }
        }

        try {
            $slotDt = Carbon::parse($dateTime);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILURE', 'Invalid dateTime format');
        }

        $reservationRef = $this->generateReservationReference();
        $expiresAt      = Carbon::now()->addMinutes(self::RESERVATION_HOLD_MINUTES);

        // Atomic: lock availability row → check vacancies → decrement → create reservation
        // Prevents double-booking when two requests hit the same slot simultaneously.
        DB::transaction(function () use ($productId, $slotDt, $totalRequested, $reservationRef, $gygBookingRef, $bookingItems, $product, $expiresAt, &$error) {
            $availability = GygAvailability::where('gyg_product_id', $productId)
                ->where('slot_datetime', $slotDt)
                ->lockForUpdate()
                ->first();

            // Slot must exist and have enough vacancies
            if (! $availability || $availability->vacancies < $totalRequested) {
                $error = $this->error('NO_AVAILABILITY', 'Not enough vacancies for the requested slot');
                return;
            }

            $availability->decrement('vacancies', $totalRequested);

            GygReservation::create([
                'reservation_reference' => $reservationRef,
                'gyg_booking_reference' => $gygBookingRef,
                'gyg_product_id'        => $productId,
                'slot_datetime'         => $slotDt,
                'booking_items'         => $bookingItems,
                'currency'              => $product->currency,
                'status'                => 'active',
                'expires_at'            => $expiresAt,
            ]);
        });

        if (isset($error)) {
            return $error;
        }

        return [
            'data' => [
                'reservationReference'  => $reservationRef,
                'reservationExpiration' => $expiresAt->toIso8601String(),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Cancel Reservation
    // -------------------------------------------------------------------------

    public function cancelReservation(array $data): array
    {
        $gygBookingRef    = $data['gygBookingReference'] ?? null;
        $reservationRef   = $data['reservationReference'] ?? null;

        if (! $gygBookingRef || ! $reservationRef) {
            return $this->error('VALIDATION_FAILURE', 'Missing gygBookingReference or reservationReference');
        }

        $reservation = GygReservation::where('reservation_reference', $reservationRef)
            ->where('gyg_booking_reference', $gygBookingRef)
            ->first();

        if (! $reservation) {
            return $this->error('INVALID_RESERVATION', 'Reservation not found');
        }

        if ($reservation->status === 'cancelled') {
            // Idempotent — already cancelled, return success
            return ['data' => []];
        }

        if ($reservation->status === 'converted') {
            return $this->error('INVALID_RESERVATION', 'Reservation has already been converted to a booking');
        }

        // Restore vacancies
        $this->restoreVacancies($reservation);

        $reservation->update(['status' => 'cancelled']);

        return ['data' => []];
    }

    // -------------------------------------------------------------------------
    // Book
    // -------------------------------------------------------------------------

    public function book(array $data): array
    {
        $productId        = $data['productId'] ?? null;
        $reservationRef   = $data['reservationReference'] ?? null;
        $gygBookingRef    = $data['gygBookingReference'] ?? null;
        $currency         = $data['currency'] ?? 'USD';
        $dateTime         = $data['dateTime'] ?? null;
        $bookingItems     = $data['bookingItems'] ?? [];
        $travelers        = $data['travelers'] ?? [];
        $travelerHotel    = $data['travelerHotel'] ?? null;
        $comment          = $data['comment'] ?? null;
        $language         = $data['language'] ?? null;

        if (! $productId || ! $reservationRef || ! $gygBookingRef || ! $dateTime || empty($bookingItems)) {
            return $this->error('VALIDATION_FAILURE', 'Missing required booking fields');
        }

        $reservation = GygReservation::where('reservation_reference', $reservationRef)
            ->where('gyg_booking_reference', $gygBookingRef)
            ->first();

        if (! $reservation) {
            return $this->error('INVALID_RESERVATION', 'Reservation not found');
        }

        if (! $reservation->isActive()) {
            if ($reservation->status === 'cancelled') {
                return $this->error('INVALID_RESERVATION', 'Reservation has been cancelled');
            }
            if ($reservation->isExpired()) {
                return $this->error('INVALID_RESERVATION', 'Reservation has expired');
            }
            if ($reservation->status === 'converted') {
                // Idempotent: find existing booking
                $existing = GygBooking::where('reservation_reference', $reservationRef)->first();
                if ($existing) {
                    return ['data' => [
                        'bookingReference' => $existing->booking_reference,
                        'tickets'          => $existing->tickets ?? [],
                    ]];
                }
            }
        }

        // Validate categories
        foreach ($bookingItems as $item) {
            if (! in_array($item['category'] ?? '', self::VALID_CATEGORIES)) {
                return $this->error('INVALID_TICKET_CATEGORY', 'Invalid category: ' . ($item['category'] ?? 'null'));
            }
        }

        try {
            $slotDt = Carbon::parse($dateTime);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILURE', 'Invalid dateTime format');
        }

        $bookingRef = $this->generateBookingReference();
        $tickets    = $this->generateTickets($bookingItems, $bookingRef);

        // Atomic: create booking + mark reservation converted in one transaction.
        // Prevents partial writes where booking exists but reservation is still 'active',
        // which would cause GYG retries to create duplicate bookings.
        DB::transaction(function () use (
            $bookingRef, $reservationRef, $gygBookingRef, $data,
            $productId, $slotDt, $bookingItems, $travelers,
            $travelerHotel, $language, $comment, $currency,
            $tickets, $reservation
        ) {
            GygBooking::create([
                'booking_reference'       => $bookingRef,
                'reservation_reference'   => $reservationRef,
                'gyg_booking_reference'   => $gygBookingRef,
                'gyg_activity_reference'  => $data['gygActivityReference'] ?? null,
                'gyg_product_id'          => $productId,
                'slot_datetime'           => $slotDt,
                'booking_items'           => $bookingItems,
                'travelers'               => $travelers,
                'traveler_hotel'          => $travelerHotel,
                'language'                => $language,
                'comment'                 => $comment,
                'currency'                => $currency,
                'tickets'                 => $tickets,
                'status'                  => 'confirmed',
            ]);

            $reservation->update(['status' => 'converted']);
        });

        return [
            'data' => [
                'bookingReference' => $bookingRef,
                'tickets'          => $tickets,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Cancel Booking
    // -------------------------------------------------------------------------

    public function cancelBooking(array $data): array
    {
        $bookingRef    = $data['bookingReference'] ?? null;
        $gygBookingRef = $data['gygBookingReference'] ?? null;
        $productId     = $data['productId'] ?? null;

        if (! $bookingRef || ! $gygBookingRef || ! $productId) {
            return $this->error('VALIDATION_FAILURE', 'Missing bookingReference, gygBookingReference, or productId');
        }

        $booking = GygBooking::where('booking_reference', $bookingRef)
            ->where('gyg_booking_reference', $gygBookingRef)
            ->first();

        if (! $booking) {
            return $this->error('INVALID_BOOKING', 'Booking not found');
        }

        if ($booking->status === 'cancelled') {
            // Idempotent
            return ['data' => []];
        }

        // Restore vacancies
        $totalItems = collect($booking->booking_items)->sum('count');
        $availability = GygAvailability::where('gyg_product_id', $booking->gyg_product_id)
            ->where('slot_datetime', $booking->slot_datetime)
            ->first();

        if ($availability) {
            $availability->increment('vacancies', $totalItems);
        }

        $booking->update(['status' => 'cancelled']);

        return ['data' => []];
    }

    // -------------------------------------------------------------------------
    // Notify
    // -------------------------------------------------------------------------

    public function notify(array $data): array
    {
        GygNotification::create([
            'notification_type' => $data['notificationType'] ?? 'UNKNOWN',
            'description'       => $data['description'] ?? null,
            'payload'           => $data,
        ]);

        return ['data' => []];
    }

    // -------------------------------------------------------------------------
    // Push availability update to GYG
    // -------------------------------------------------------------------------

    /**
     * Notify GYG that availability has changed for a product.
     * Called whenever we update gyg_availabilities for a product.
     */
    public function notifyAvailabilityUpdate(string $productId): array
    {
        $url      = config('services.gyg.api_url') . '/1/notify-availability-update';
        $username = config('services.gyg.username');
        $password = config('services.gyg.password');

        try {
            $response = Http::withBasicAuth($username, $password)
                ->timeout(15)
                ->post($url, [
                    'productId' => $productId,
                ]);

            Log::info('GYG availability update sent', [
                'productId' => $productId,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);

            return [
                'success' => $response->successful(),
                'status'  => $response->status(),
                'body'    => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('GYG availability update failed', [
                'productId' => $productId,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateReservationReference(): string
    {
        return 'RES-' . strtoupper(Str::random(16));
    }

    private function generateBookingReference(): string
    {
        // Max 25 chars per GYG spec
        return 'BK-' . strtoupper(Str::random(18)); // 3 + 1 + 18 = 22 chars
    }

    private function generateTickets(array $bookingItems, string $bookingRef): array
    {
        $tickets = [];
        foreach ($bookingItems as $item) {
            $count    = (int) ($item['count'] ?? 1);
            $category = $item['category'] ?? 'ADULT';
            for ($i = 0; $i < $count; $i++) {
                $tickets[] = [
                    'category'       => $category,
                    'ticketCode'     => strtoupper($bookingRef . '-' . $category . '-' . ($i + 1)),
                    'ticketCodeType' => 'QR_CODE',
                ];
            }
        }
        return $tickets;
    }

    private function restoreVacancies(GygReservation $reservation): void
    {
        $totalItems   = collect($reservation->booking_items)->sum('count');
        $availability = GygAvailability::where('gyg_product_id', $reservation->gyg_product_id)
            ->where('slot_datetime', $reservation->slot_datetime)
            ->first();

        if ($availability) {
            $availability->increment('vacancies', $totalItems);
        }
    }

    private function error(string $code, string $message): array
    {
        return [
            'errorCode'    => $code,
            'errorMessage' => $message,
        ];
    }
}
