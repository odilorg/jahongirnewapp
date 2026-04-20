<?php

namespace App\Actions\BookingBot\Handlers;

use App\Models\RoomUnitMapping;
use App\Services\Beds24BookingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handles "view bookings" intent from @j_booking_hotel_bot.
 *
 * Serves both typed-text intents (handleCommand) and inline-button
 * filter_type callbacks (view_arrivals_today, view_departures_today,
 * view_current, view_new). Same orchestration — the caller sets the
 * filter_type in $parsed.
 *
 * Pure extraction from ProcessBookingMessage::handleViewBookings plus one
 * structural split: reply formatting pulled into a private helper.
 * Behaviour must be byte-identical — the golden master asserts this.
 *
 * Known principle deviation carried from the original: RoomUnitMapping is
 * queried directly (P6-P7 concern, plan §4.3 follow-up).
 */
final class ViewBookingsFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
    ) {}

    public function execute(array $parsed): string
    {
        try {
            $filters = [];
            $filterType = $parsed['filter_type'] ?? null;

            $rooms = RoomUnitMapping::all();
            $propertyIds = $rooms->pluck('property_id')->unique()->toArray();
            $filters['propertyId'] = $propertyIds;

            if ($filterType) {
                switch ($filterType) {
                    case 'arrivals_today':
                        $today = date('Y-m-d');
                        $filters['arrivalFrom'] = $today;
                        $filters['arrivalTo'] = $today;
                        $title = "Arrivals Today (" . date('M j, Y') . ")";
                        break;

                    case 'departures_today':
                        $today = date('Y-m-d');
                        $filters['departureFrom'] = $today;
                        $filters['departureTo'] = $today;
                        $title = "Departures Today (" . date('M j, Y') . ")";
                        break;

                    case 'current':
                        // In-house guests: arrived by today, leaving after today.
                        $today = date('Y-m-d');
                        $filters['arrivalTo'] = $today;
                        $filters['departureFrom'] = date('Y-m-d', strtotime('+1 day'));
                        $title = "Current Bookings (In-House)";
                        break;

                    case 'new':
                        $filters['status'] = ['new', 'request'];
                        $title = "New Bookings (Unconfirmed)";
                        break;

                    default:
                        $filters['arrivalFrom'] = date('Y-m-d');
                        $title = "Upcoming Bookings";
                }
            } else {
                $filters['arrivalFrom'] = date('Y-m-d');
                $title = "Upcoming Bookings";
            }

            if (isset($parsed['search_string']) && !empty($parsed['search_string'])) {
                $filters['searchString'] = $parsed['search_string'];
                $title = "Search Results: " . $parsed['search_string'];
            }

            $dates = $parsed['dates'] ?? null;
            if ($dates) {
                if (!empty($dates['check_in'])) {
                    $filters['arrivalFrom'] = $dates['check_in'];
                }
                if (!empty($dates['check_out'])) {
                    $filters['arrivalTo'] = $dates['check_out'];
                }
            }

            Log::info('Fetching bookings', ['filters' => $filters, 'title' => $title]);

            $result = $this->beds24->getBookings($filters);

            Log::info('Bookings result', [
                'success' => $result['success'] ?? false,
                'count' => $result['count'] ?? 0,
                'has_data' => isset($result['data']),
                'data_empty' => empty($result['data']),
            ]);

            if (!isset($result['data']) || empty($result['data'])) {
                return "📭 No Bookings Found\n\n" .
                       "Filter: {$title}\n" .
                       "Date: " . date('M j, Y') . "\n\n" .
                       "No bookings match your search criteria.";
            }

            return $this->formatBookingsReply($result['data'], $title, $rooms);

        } catch (\Exception $e) {
            Log::error('View bookings failed', [
                'error' => $e->getMessage(),
                'parsed' => $parsed,
            ]);

            return "Error fetching bookings: " . $e->getMessage() . "\n\n" .
                   "Please try again or contact support.";
        }
    }

    /**
     * Render the booking list reply. Extracted only for readability; still a
     * private, behaviour-preserving helper on this Action.
     */
    private function formatBookingsReply(array $bookings, string $title, Collection $rooms): string
    {
        $count = count($bookings);

        $response = "{$title}\n";
        $response .= "━━━━━━━━━━━━━━━━━━━━\n";
        $response .= "Found {$count} " . ($count == 1 ? 'booking' : 'bookings') . "\n\n";

        // Telegram has a message-length ceiling; cap at 10 and hint at the rest.
        $displayCount = min($count, 10);

        for ($i = 0; $i < $displayCount; $i++) {
            $booking = $bookings[$i];

            $guestName = trim(($booking['firstName'] ?? '') . ' ' . ($booking['lastName'] ?? ''));
            if (empty($guestName)) {
                $guestName = 'N/A';
            }

            $roomName = 'N/A';
            if (isset($booking['roomId'])) {
                $roomMapping = $rooms->where('room_id', $booking['roomId'])->first();
                if ($roomMapping) {
                    $roomName = $roomMapping->room_name . ' (Unit ' . $roomMapping->unit_name . ')';
                }
            }

            $response .= "#{$booking['id']}\n";
            $response .= "Guest: {$guestName}\n";
            $response .= "Room: {$roomName}\n";
            $response .= "Dates: " . ($booking['arrival'] ?? 'N/A') . " → " . ($booking['departure'] ?? 'N/A') . "\n";

            if (isset($booking['status'])) {
                $statusEmoji = match ($booking['status']) {
                    'confirmed' => '✅',
                    'request' => '❓',
                    'cancelled' => '❌',
                    'new' => '🆕',
                    default => '•',
                };
                $response .= "Status: {$statusEmoji} " . ucfirst($booking['status']) . "\n";
            }

            if (isset($booking['numAdult']) || isset($booking['numChild'])) {
                $adults = $booking['numAdult'] ?? 0;
                $children = $booking['numChild'] ?? 0;
                $response .= "Guests: {$adults} " . ($adults == 1 ? 'adult' : 'adults');
                if ($children > 0) {
                    $response .= ", {$children} " . ($children == 1 ? 'child' : 'children');
                }
                $response .= "\n";
            }

            if (isset($booking['price'])) {
                $response .= "Price: $" . number_format($booking['price'], 2) . "\n";
            }

            if ($i < $displayCount - 1) {
                $response .= "─────────────────────\n";
            }
            $response .= "\n";
        }

        if ($count > 10) {
            $response .= "... and " . ($count - 10) . " more bookings\n";
            $response .= "(Showing first 10 results)\n";
        }

        $response .= "\nTo modify: modify booking #[ID]\n";
        $response .= "To cancel: cancel booking #[ID]";

        return $response;
    }
}
