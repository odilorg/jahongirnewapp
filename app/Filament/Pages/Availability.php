<?php

namespace App\Filament\Pages;

use Illuminate\Support\Facades\Log;
use Filament\Forms;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
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

    public function submit()
    {
        $this->validate([
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date',
            'hotel' => 'required',
        ]);

        try {
            $token = env('BEDS24_API_TOKEN');

            if (!$token) {
                Notification::make()->title('API token is missing!')->danger()->send();
                return;
            }

            $response = Http::withHeaders([
                'token' => $token,
            ])->get('https://beds24.com/api/v2/inventory/rooms/unitBookings', [
                'propertyId' => $this->hotel,
                'startDate' => $this->arrival_date,
                'endDate' => $this->departure_date,
            ]);

            $data = $response->json();

            if (isset($data['success']) && $data['success']) {
                $this->available_rooms = collect($data['data'])->map(function ($room) {
                    $roomName = $room['name'];
                    $qty = (int) $room['qty'];
                    $unitBookings = $room['unitBookings'];

                    // Extract relevant dates (excluding departure date)
                    $dates = array_keys($unitBookings);
                    $relevantDates = array_slice($dates, 0, -1); // All dates except the last one

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
                            'available_qty' => $qty,
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
                Notification::make()->title('Failed to fetch room availability!')->danger()->send();
            }
        } catch (\Exception $e) {
            Notification::make()->title('An error occurred while fetching room availability!')->danger()->send();
        }
    }
}
