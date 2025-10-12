<?php

namespace App\Services;

use App\Models\AuthorizedStaff;
use App\Models\RoomUnitMapping;
use Illuminate\Support\Facades\Log;

/**
 * BookingCommandHandler
 * 
 * Handles all booking-related commands for the Telegram bot.
 * Commands are processed after NL parsing by BookingIntentParser.
 * 
 * Future: Extract command logic from ProcessBookingMessage job
 * to keep the job focused on message processing only.
 */
class BookingCommandHandler
{
    public function __construct(
        protected Beds24BookingService $beds24Service
    ) {}

    /**
     * Route command to appropriate handler
     */
    public function handle(array $parsed, AuthorizedStaff $staff): string
    {
        $intent = $parsed['intent'] ?? 'unknown';

        return match($intent) {
            'check_availability' => $this->handleCheckAvailability($parsed),
            'create_booking' => $this->handleCreateBooking($parsed, $staff),
            'view_bookings' => $this->handleViewBookings($parsed),
            'modify_booking' => $this->handleModifyBooking($parsed, $staff),
            'cancel_booking' => $this->handleCancelBooking($parsed, $staff),
            default => $this->handleUnknown()
        };
    }

    /**
     * Placeholder methods - implementation in ProcessBookingMessage for now
     */
    protected function handleCheckAvailability(array $parsed): string
    {
        return 'Check availability - implemented in ProcessBookingMessage';
    }

    protected function handleCreateBooking(array $parsed, AuthorizedStaff $staff): string
    {
        return 'Create booking - implemented in ProcessBookingMessage';
    }

    protected function handleViewBookings(array $parsed): string
    {
        return 'View bookings feature coming soon!';
    }

    protected function handleModifyBooking(array $parsed, AuthorizedStaff $staff): string
    {
        return 'Modify booking feature coming soon!';
    }

    protected function handleCancelBooking(array $parsed, AuthorizedStaff $staff): string
    {
        return 'Cancel booking feature coming soon!';
    }

    protected function handleUnknown(): string
    {
        return I did not quite understand that. Try:nn .
               - check avail dec 10-12n .
               - book room 12 under John Walker dec 10-12 tel +1234567890 email ok@ok.comn .
               - show booking 12345n .
               - help;
    }
}
