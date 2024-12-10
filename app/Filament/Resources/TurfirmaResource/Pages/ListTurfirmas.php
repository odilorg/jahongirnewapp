<?php
namespace App\Filament\Resources\TurfirmaResource\Pages;

use App\Filament\Resources\TurfirmaResource;
use App\Models\Turfirma;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ListTurfirmas extends ListRecords
{
    protected static string $resource = TurfirmaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('create_from_tin')
                ->label('Create from TIN')
                ->modalHeading('Create Turfirma from TIN')
                ->form([
                    Forms\Components\TextInput::make('tin')
                        ->label('TIN')
                        ->required()
                        ->numeric()
                        ->minLength(9)
                        ->maxLength(9)
                        ->hint('Enter the 9-digit TIN'),
                ])
                ->action(function (array $data) {
                    // Check if the company already exists in the database
                    $existingTurfirma = Turfirma::where('inn', $data['tin'])->first();
                    if ($existingTurfirma) {
                        Notification::make()
                            ->title('Duplicate Entry')
                            ->body('A company with this TIN already exists.')
                            ->success()
                            ->send();

                        return;
                    }

                    // Try the primary API endpoint
                    $primaryResponse = Http::get("https://gnk-api.didox.uz/api/v1/utils/info/{$data['tin']}");
                    $apiData = null;

                    if ($primaryResponse->successful() && !empty($primaryResponse->json('shortName')) && !empty($primaryResponse->json('name'))) {
                        $apiData = $primaryResponse->json();
                    } else {
                        // If the primary API fails, try the first backup endpoint
                        $backupResponse = Http::get("https://new.soliqservis.uz/api/np1/bytin/factura?tinOrPinfl={$data['tin']}");
                        if ($backupResponse->successful() && !empty($backupResponse->json('shortName')) && !empty($backupResponse->json('name'))) {
                            $apiData = $backupResponse->json();
                        } else {
                            // If the first backup API fails, try the second backup endpoint
                            $secondBackupResponse = Http::get("https://stage.goodsign.biz/v1/utils/info/{$data['tin']}");
                            if ($secondBackupResponse->successful() && !empty($secondBackupResponse->json('shortName')) && !empty($secondBackupResponse->json('name'))) {
                                $apiData = $secondBackupResponse->json();
                            }
                        }
                    }

                    // If no API returned valid data
                    if (!$apiData) {
                        Notification::make()
                            ->title('Error Fetching Data')
                            ->body('All APIs are down, or the TIN is invalid. Please add the company details manually.')
                            ->danger()
                            ->send();

                        throw ValidationException::withMessages([
                            'tin' => 'Failed to fetch data from all APIs. Please verify the TIN or add the data manually.',
                        ]);
                    }

                    // Insert the new company data into the database
                    Turfirma::create([
                        'name' => $apiData['shortName'] ?? null,
                        'official_name' => $apiData['name'] ?? null,
                        'address_street' => $apiData['address'] ?? null,
                        'inn' => $apiData['tin'] ?? $data['tin'],
                        'account_number' => $apiData['account'] ?? null,
                        'bank_mfo' => $apiData['mfo'] ?? null,
                        'director_name' => $apiData['director'] ?? null,
                    ]);

                    // Notify the user of success
                    Notification::make()
                        ->title('Company Created')
                        ->body('The company has been successfully created.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
