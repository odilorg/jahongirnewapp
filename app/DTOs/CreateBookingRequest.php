<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\Carbon;

readonly class CreateBookingRequest
{
    /**
     * @param BookingRoomRequest[] $rooms  Normalized, deduplicated list
     * @param BookingFinance|null  $finance Optional quoted total. Null means no price was provided.
     */
    public function __construct(
        public string         $guestName,
        public string         $guestPhone,
        public string         $guestEmail,
        public string         $checkIn,
        public string         $checkOut,
        public array          $rooms,
        public string         $createdBy,      // staff name for booking notes
        public ?BookingFinance $finance = null, // optional quoted total at booking time
    ) {}

    /**
     * Build from a raw DeepSeek-parsed intent array + the acting staff member's name.
     */
    public static function fromParsed(array $parsed, string $createdBy): self
    {
        return new self(
            guestName:  trim($parsed['guest']['name']         ?? ''),
            guestPhone: trim($parsed['guest']['phone']        ?? ''),
            guestEmail: trim($parsed['guest']['email']        ?? ''),
            checkIn:    trim($parsed['dates']['check_in']     ?? ''),
            checkOut:   trim($parsed['dates']['check_out']    ?? ''),
            rooms:      BookingRoomRequest::fromParsed($parsed),
            createdBy:  $createdBy,
            finance:    BookingFinance::fromParsed($parsed),
        );
    }

    /**
     * Returns a user-facing validation error string, or null when the request is valid.
     *
     * Validates:
     *   - presence of required fields
     *   - parseable, ordered dates (check-in < check-out via Carbon)
     *   - at least one room requested
     *   - duplicate room requests (operator typo guard)
     */
    public function validationError(): ?string
    {
        if ($this->guestName === '') {
            return 'Please provide guest name. Example: book room 12 under John Walker...';
        }

        if ($this->checkIn === '' || $this->checkOut === '') {
            return 'Please provide check-in and check-out dates.';
        }

        // Validate both dates are parseable and logically ordered
        try {
            $checkInDate  = Carbon::parse($this->checkIn);
            $checkOutDate = Carbon::parse($this->checkOut);
        } catch (\Exception) {
            return "Dates could not be parsed. Use YYYY-MM-DD format (e.g. 2025-03-15).";
        }

        if (!$checkInDate->lt($checkOutDate)) {
            return "Check-in date must be before check-out date.\n"
                 . "Provided: {$this->checkIn} → {$this->checkOut}";
        }

        if (empty($this->rooms)) {
            return 'Please specify at least one room. Example: book room 12 under...';
        }

        // Validate optional quoted total — a provided-but-invalid amount is an error, not silent null.
        if ($this->finance !== null) {
            if ($error = $this->finance->validationError()) {
                return $error;
            }
        }

        // Guard against duplicate room requests (e.g. "book rooms 12 and 12 under...").
        // BookingRoomRequest::fromParsed() returns the raw list without silent deduplication,
        // so this check operates on the actual operator input.
        $allUnits  = array_map(fn($r) => $r->unitName . '|' . ($r->propertyHint ?? ''), $this->rooms);
        $uniqueSet = array_unique($allUnits);

        if (count($allUnits) !== count($uniqueSet)) {
            $dupes = array_diff_key($allUnits, array_unique($allUnits));
            $units = implode(', ', array_unique(array_map(fn($k) => explode('|', $k)[0], $dupes)));
            return "Duplicate room(s) in your request: {$units}. "
                 . 'Each room should appear only once per booking command.';
        }

        return null;
    }
}
