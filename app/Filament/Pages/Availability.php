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
        Log::info('API Response:', $data);

        if (isset($data['success']) && $data['success']) {
            $this->available_rooms = collect($data['data'])->map(function ($room) {
                if ($room['name'] === '1 xona') {
                    Log::info("Skipping Room: {$room['name']}");
                    return null;
                }

                Log::info("Processing Room: {$room['name']}");

                // Filter relevant dates: Arrival date to one day before departure
                $dates = collect($room['unitBookings'])->filter(function ($_, $date) {
                    return $date < $this->departure_date; // Exclude departure date
                });
                Log::info("Relevant Dates for Room: {$room['name']}", ['dates' => $dates]);

                // Extract unit numbers (excluding 'unassigned')
                $units = array_keys($room['unitBookings'][$dates->keys()->first()]);
                $units = array_filter($units, fn($unit) => $unit !== 'unassigned');
                Log::info("Units for Room: {$room['name']}", ['units' => $units]);

                // Switching Logic: Check if a single unit is consistently available for all dates
                $switchingAvailableUnits = collect($units)->filter(function ($unit) use ($dates, $room) {
                    $isAvailable = $dates->every(function ($day) use ($unit) {
                        return isset($day[$unit]) && $day[$unit] === 0; // Unit is available for all dates
                    });
                    Log::info("Checking Unit {$unit} for Room: {$room['name']}", ['isAvailable' => $isAvailable]);
                    return $isAvailable;
                })->count();
                
                Log::info("Switching Available Units for Room: {$room['name']}", ['switchingAvailableUnits' => $switchingAvailableUnits]);

                // Room qualifies for switching only if at least one unit is consistently available
                if ($switchingAvailableUnits > 0) {
                    return [
                        'name' => $room['name'],
                        'available_qty' => $switchingAvailableUnits,
                        'switching_required' => true,
                    ];
                }

                Log::info("Room does not qualify for Switching: {$room['name']}");
                return null;
            })->filter()->values()->toArray();

            Log::info('Available Rooms:', ['available_rooms' => $this->available_rooms]);

            Notification::make()
                ->title(count($this->available_rooms) > 0 ? 'Rooms found successfully!' : 'No rooms available.')
                ->success()
                ->send();
        } else {
            Notification::make()->title('Failed to fetch room availability!')->danger()->send();
        }
    } catch (\Exception $e) {
        Log::error('Error during room availability check:', ['exception' => $e->getMessage()]);
        Notification::make()->title('An error occurred while fetching room availability!')->danger()->send();
    }
}


    

    

}
