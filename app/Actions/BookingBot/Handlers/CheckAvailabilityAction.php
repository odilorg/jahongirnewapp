<?php

namespace App\Actions\BookingBot\Handlers;

use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handles "check availability" intent from @j_booking_hotel_bot.
 *
 * Pure extraction from ProcessBookingMessage::handleCheckAvailability plus one
 * structural split: the 60-odd lines of reply formatting are pulled into a
 * private formatAvailabilityReply() method so execute() stays readable.
 * Behaviour must be byte-identical — the golden master asserts this.
 *
 * Known principle deviation carried from the original: this Action queries
 * RoomUnitMapping directly. That's a P6-P7 concern (domain data lookup should
 * live in a scope or lookup Service). Deliberately unchanged in this commit;
 * tracked as follow-up.
 */
final class CheckAvailabilityAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
    ) {}

    public function execute(array $parsed): string
    {
        $dates = $parsed['dates'] ?? null;

        if (!$dates || empty($dates['check_in']) || empty($dates['check_out'])) {
            return 'Please provide valid dates. Example: check avail jan 2-3';
        }

        $checkIn = $dates['check_in'];
        $checkOut = $dates['check_out'];

        $rooms = RoomUnitMapping::all();

        if ($rooms->isEmpty()) {
            return 'No rooms configured in system.';
        }

        try {
            $propertyIds = $rooms->pluck('property_id')->unique()->toArray();

            $availability = $this->beds24->checkAvailability($checkIn, $checkOut, $propertyIds);

            if (!$availability['success']) {
                throw new \Exception($availability['error'] ?? 'API request failed');
            }

            $availableRoomsApi = $availability['availableRooms'] ?? [];
            $nights = $availability['nights'] ?? [];
            $nightCount = count($nights);

            // Project the API response onto our local unit inventory:
            // API tells us "N rooms of type X are free"; we surface the first
            // N units alphabetically so staff get a stable, nameable set.
            $availableUnits = [];
            foreach ($availableRoomsApi as $apiRoom) {
                $roomId = $apiRoom['roomId'];
                $quantity = $apiRoom['quantity'];
                $roomName = $apiRoom['roomName'];
                $propertyId = $apiRoom['propertyId'];

                $units = $rooms->where('room_id', $roomId)
                               ->where('property_id', $propertyId)
                               ->sortBy('unit_name')
                               ->take($quantity);

                foreach ($units as $unit) {
                    $availableUnits[] = [
                        'unit' => $unit,
                        'quantity' => $quantity,
                        'roomName' => $roomName,
                    ];
                }
            }

            return $this->formatAvailabilityReply($availableUnits, $checkIn, $checkOut, $nightCount);

        } catch (\Exception $e) {
            Log::error('Availability check failed', ['error' => $e->getMessage()]);

            return "Error checking availability: " . $e->getMessage() . "\n\n" .
                   "Please try again or contact support.";
        }
    }

    /**
     * Render the human-readable availability reply. Extracted into a helper
     * only for readability — still an internal, behaviour-preserving split.
     */
    private function formatAvailabilityReply(array $availableUnits, string $checkIn, string $checkOut, int $nightCount): string
    {
        if (empty($availableUnits)) {
            return "No rooms available for the entire stay.\n" .
                   "Check-in: {$checkIn}\n" .
                   "Check-out: {$checkOut}\n\n" .
                   "All rooms are booked for at least one night in this period.";
        }

        // Compact date banner: "Oct 16–19".
        $checkInDt = new \DateTimeImmutable($checkIn);
        $checkOutDt = new \DateTimeImmutable($checkOut);
        $monthName = $checkInDt->format('M');
        $startDay = $checkInDt->format('j');
        $endDay = $checkOutDt->format('j');
        $dateRange = "{$monthName} {$startDay}–{$endDay}";

        // "both nights" / "all three nights" reads better than "2 nights" at these boundaries.
        if ($nightCount == 1) {
            $nightText = 'night';
        } elseif ($nightCount == 2) {
            $nightText = 'both nights';
        } elseif ($nightCount == 3) {
            $nightText = 'all three nights';
        } else {
            $nightText = "all {$nightCount} nights";
        }

        $response = "Rooms available for the entire stay ({$nightText}):\n\n";

        /** @var Collection $byProperty */
        $byProperty = collect($availableUnits)->groupBy(function ($item) {
            return $item['unit']->property_name;
        });

        foreach ($byProperty as $propertyName => $propertyUnits) {
            $response .= "━━━━━ " . strtoupper($propertyName) . " ━━━━━\n\n";

            $byRoomType = $propertyUnits->groupBy('roomName');

            foreach ($byRoomType as $roomTypeName => $typeUnits) {
                $totalQty = $typeUnits->first()['quantity'];
                $units = $typeUnits->pluck('unit');

                $response .= "{$roomTypeName} — {$totalQty} " . ($totalQty == 1 ? 'room' : 'rooms') . "\n";
                $response .= "Units: " . $units->pluck('unit_name')->sort()->implode(', ') . "\n";

                $firstUnit = $units->first();
                $response .= "Type: " . ucfirst($firstUnit->room_type) . " | Max: {$firstUnit->max_guests} guests\n";
                if ($firstUnit->base_price > 0) {
                    $response .= "Price: $" . $firstUnit->base_price . "/night\n";
                }
                $response .= "\n";
            }
        }

        $response .= "All other room types break on at least one night, so they're not available for the whole {$dateRange} stay.\n\n";
        $response .= "To book, use: book room [NUMBER] under [NAME] {$checkIn} to {$checkOut} tel [PHONE] email [EMAIL]";

        return $response;
    }
}
