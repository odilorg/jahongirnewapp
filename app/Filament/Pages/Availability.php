<?php

namespace App\Filament\Pages;

use Carbon\Carbon;
use Filament\Forms;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Filament\Notifications\Notification;

class Availability extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Hotel Management';
    protected static ?string $navigationLabel = 'Room Availability';
    protected static string $view = 'filament.pages.availability';

    public $arrival_date;
    public $departure_date;
    public $hotel;
    public $available_rooms = []; // Stores room names from the response

    // Token management consolidated into Beds24BookingService (single source of truth)

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('arrival_date')
                ->label('Arrival Date')
                ->required(),
            Forms\Components\DatePicker::make('departure_date')
                ->label('Departure Date')
                ->required(),
            Forms\Components\Select::make('hotel')
                ->label('Hotel')
                ->options([
                    41097 => 'Jahongir Hotel',
                    172793 => 'Jahongir Premium Hotel',
                ])
                ->required(),
        ];
    }

    private function getApiToken(): ?string
    {
        try {
            $service = app(\App\Services\Beds24BookingService::class);
            // Use reflection to call protected getToken() - or use forceRefresh
            $result = $service->forceRefresh();
            if ($result['success']) {
                return \Illuminate\Support\Facades\Cache::get('beds24_access_token');
            }
            Log::error('Availability: Token refresh failed', $result);
            return null;
        } catch (\Throwable $e) {
            Log::error('Availability: Token error', ['error' => $e->getMessage()]);
            return null;
        }
    }


    public function submit()
    {
        $this->validate([
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date|after:arrival_date', // Ensures departure_date is after arrival_date
            'hotel' => 'required',
        ]);

        try {
            $token = $this->getApiToken();

            if (!$token) {
                Notification::make()->title('Failed to authenticate!')->danger()->send();
                return;
            }

            // Format dates to match API requirements (YYYY-MM-DD)
            $formattedArrivalDate = Carbon::parse($this->arrival_date)->format('Y-m-d');
            $formattedDepartureDate = Carbon::parse($this->departure_date)->format('Y-m-d');

            // Prepare request parameters
            $requestParams = [
                'propertyId' => $this->hotel,
                'startDate' => $formattedArrivalDate,
                'endDate' => $formattedDepartureDate,
            ];

            $requestHeaders = [
                'token' => $token,
            ];

            // Log API request details for debugging
            Log::info('API Request Parameters', $requestParams);
            Log::info('API Request Headers', $requestHeaders);

            // Make the API request
            $response = Http::withHeaders($requestHeaders)->get('https://beds24.com/api/v2/inventory/rooms/unitBookings', $requestParams);

            if ($response->failed()) {
                Log::error('Failed API Response', ['response' => $response->body()]);
                Notification::make()->title('Failed to fetch room availability!')->danger()->send();
                return;
            }

            $data = $response->json();

            if (isset($data['success']) && $data['success']) {
                $this->available_rooms = collect($data['data'])->map(function ($room) {
                    $roomName = $room['name'];
                    $qty = (int) $room['qty'];
                    $unitBookings = $room['unitBookings'];

                    // Extract relevant dates (excluding departure date)
                    $dates = array_keys($unitBookings);
                    $relevantDates = array_slice($dates, 0, -1);

                    // Check fully available units
                    $fullyAvailableUnits = 0;
                    foreach (range(1, $qty) as $unit) {
                        $isFullyAvailable = true;
                        foreach ($relevantDates as $date) {
                            if (!isset($unitBookings[$date][$unit]) || $unitBookings[$date][$unit] !== 0) {
                                $isFullyAvailable = false;
                                break;
                            }
                        }
                        if ($isFullyAvailable) {
                            $fullyAvailableUnits++;
                        }
                    }

                    if ($fullyAvailableUnits > 0) {
                        return [
                            'name' => $roomName,
                            'available_qty' => $fullyAvailableUnits,
                            'total_qty' => $qty,
                            'price' => mt_rand(50, 100), // Placeholder price
                            'switching_required' => false,
                        ];
                    }

                    // Check switching availability
                    $isSwitchingAvailable = true;
                    foreach ($relevantDates as $date) {
                        $availableUnits = 0;
                        foreach (range(1, $qty) as $unit) {
                            if (isset($unitBookings[$date][$unit]) && $unitBookings[$date][$unit] === 0) {
                                $availableUnits++;
                            }
                        }
                        if ($availableUnits < 1) {
                            $isSwitchingAvailable = false;
                            break;
                        }
                    }

                    if ($isSwitchingAvailable) {
                        return [
                            'name' => $roomName,
                            'available_qty' => 0,
                            'total_qty' => $qty,
                            'price' => mt_rand(50, 100), // Placeholder price
                            'switching_required' => true,
                        ];
                    }

                    return null;
                })->filter()->values()->toArray();

                Notification::make()
                    ->title(count($this->available_rooms) > 0 ? 'Rooms found successfully!' : 'No rooms available.')
                    ->success()
                    ->send();
            } else {
                Log::error('API Response Error', ['response' => $data]);
                Notification::make()->title('Failed to fetch room availability!')->danger()->send();
            }
        } catch (\Exception $e) {
            Log::error('Error fetching room availability: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'arrival_date' => $this->arrival_date,
                'departure_date' => $this->departure_date,
            ]);
            Notification::make()->title('An error occurred while fetching room availability!')->danger()->send();
        }
    }



    public function checkAvailability(Request $request)
{
    $validated = $request->validate([
        'arrival_date' => 'required|date',
        'departure_date' => 'required|date|after:arrival_date',
    ]);

    // List of hotels to query
    $hotels = [
        41097 => 'Jahongir Hotel',
        172793 => 'Jahongir Premium Hotel',
    ];

    try {
        $token = $this->getApiToken();

        if (!$token) {
            return response()->json(['message' => 'Failed to authenticate with the API'], 401);
        }

        $formattedArrivalDate = Carbon::parse($validated['arrival_date'])->format('Y-m-d');
        $formattedDepartureDate = Carbon::parse($validated['departure_date'])->format('Y-m-d');

        $allHotelData = []; // Store availability data for all hotels

        foreach ($hotels as $hotelId => $hotelName) {
            // Prepare request parameters for each hotel
            $requestParams = [
                'propertyId' => $hotelId,
                'startDate' => $formattedArrivalDate,
                'endDate' => $formattedDepartureDate,
            ];

            $requestHeaders = [
                'token' => $token,
            ];

            // Query the API for each hotel
            $response = Http::withHeaders($requestHeaders)->get('https://beds24.com/api/v2/inventory/rooms/unitBookings', $requestParams);

            if ($response->failed()) {
                Log::error("Failed API Response for Hotel ID: {$hotelId}", ['response' => $response->body()]);
                $allHotelData[$hotelId] = [
                    'hotel_name' => $hotelName,
                    'available_rooms' => [],
                    'error' => 'Failed to fetch availability',
                ];
                continue;
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                Log::error("Invalid API Response for Hotel ID: {$hotelId}", ['response' => $data]);
                $allHotelData[$hotelId] = [
                    'hotel_name' => $hotelName,
                    'available_rooms' => [],
                    'error' => 'Invalid API response',
                ];
                continue;
            }

            // Process available rooms for the current hotel
            $availableRooms = collect($data['data'])->map(function ($room) {
                $roomName = $room['name'];
                $qty = (int)$room['qty'];
                $unitBookings = $room['unitBookings'];

                $dates = array_keys($unitBookings);
                $relevantDates = array_slice($dates, 0, -1);

                $fullyAvailableUnits = 0;
                foreach (range(1, $qty) as $unit) {
                    $isFullyAvailable = true;
                    foreach ($relevantDates as $date) {
                        if (!isset($unitBookings[$date][$unit]) || $unitBookings[$date][$unit] !== 0) {
                            $isFullyAvailable = false;
                            break;
                        }
                    }
                    if ($isFullyAvailable) {
                        $fullyAvailableUnits++;
                    }
                }

                if ($fullyAvailableUnits > 0) {
                    return [
                        'name' => $roomName,
                        'available_qty' => $fullyAvailableUnits,
                        'total_qty' => $qty,
                        'price' => mt_rand(50, 100),
                        'switching_required' => false,
                    ];
                }

                $isSwitchingAvailable = true;
                foreach ($relevantDates as $date) {
                    $availableUnits = 0;
                    foreach (range(1, $qty) as $unit) {
                        if (isset($unitBookings[$date][$unit]) && $unitBookings[$date][$unit] === 0) {
                            $availableUnits++;
                        }
                    }
                    if ($availableUnits < 1) {
                        $isSwitchingAvailable = false;
                        break;
                    }
                }

                if ($isSwitchingAvailable) {
                    return [
                        'name' => $roomName,
                        'available_qty' => 0,
                        'total_qty' => $qty,
                        'price' => mt_rand(50, 100),
                        'switching_required' => true,
                    ];
                }

                return null;
            })->filter()->values()->toArray();

            // Add data for the current hotel to the result
            $allHotelData[$hotelId] = [
                'hotel_name' => $hotelName,
                'available_rooms' => $availableRooms,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $allHotelData,
        ]);

    } catch (\Exception $e) {
        Log::error('Error fetching availability', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['message' => 'An error occurred while fetching room availability'], 500);
    }
}


}
