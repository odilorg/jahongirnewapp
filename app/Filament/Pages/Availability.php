<?php

namespace App\Filament\Pages;

use Illuminate\Support\Facades\Log;
use Filament\Forms;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Filament\Notifications\Notification;
use Carbon\Carbon;

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

    private const TOKEN_REFRESH_URL = 'https://beds24.com/api/v2/authentication/token';
    private const REFRESH_TOKEN = 'cR6Piq23D9R2w88gjhMLR6wqlM+Wy4VaNtLDRuAjG0w2sEgnKG9mvyxsFvi+288DRWYVeUyuoxvV5O/guvf9fDwBX4ebFu+SyWnXXe8Dmh3gThoV2bC+uSBrxOrPZEKC1Fh7SDvUY+aI7rmESCZwylcpnV5QQome8OsF0hArA5A=';

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
                    41097 => 'Hotel A',
                    172793 => 'Hotel B',
                ])
                ->required(),
        ];
    }

    private function getApiToken(): ?string
{
    $token = Cache::get('beds24_api_token'); // Check if the token exists in cache

    if ($token) {
        return $token; // Use cached token
    }

    // Fetch a new token using the refresh token
    $response = Http::withHeaders(['refreshToken' => self::REFRESH_TOKEN])
        ->get(self::TOKEN_REFRESH_URL);

    if ($response->successful()) {
        $data = $response->json();

        // Check if the token exists in the response
        if (isset($data['token']) && isset($data['expiresIn'])) {
            $expiresInSeconds = (int) $data['expiresIn'];

            // Cache the new token
            Cache::put('beds24_api_token', $data['token'], now()->addSeconds($expiresInSeconds));

            Log::info('API token refreshed successfully', [
                'token' => $data['token'],
                'expires_in' => $expiresInSeconds,
            ]);

            return $data['token'];
        }

        Log::error('Unexpected response format during token refresh', ['response' => $data]);
        return null;
    }

    Log::error('Failed API token refresh response', ['response' => $response->body()]);
    return null; // Return null if refreshing fails
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
}
