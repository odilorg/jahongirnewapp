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

                    // Fetch data from the API
                    $response = Http::get("https://gnk-api.didox.uz/api/v1/utils/info/{$data['tin']}");

                    if (!$response->successful() || empty($response->json('shortName')) || empty($response->json('name'))) {
                        // Show a notification if the API fetch fails
                        Notification::make()
                            ->title('Error Fetching Data')
                            ->body('The database is down, or the TIN is invalid. Please add the company details manually.')
                            ->danger()
                            ->send();

                        throw ValidationException::withMessages([
                            'tin' => 'Failed to fetch data. Please verify the TIN or add the data manually.',
                        ]);
                    }

                    $companyData = $response->json();

                    // Insert the new company data into the database
                    Turfirma::create([
                        'name' => $companyData['shortName'] ?? null,
                        'official_name' => $companyData['name'] ?? null,
                        'address_street' => $companyData['address'] ?? null,
                        'inn' => $companyData['tin'] ?? $data['tin'],
                        'account_number' => $companyData['account'] ?? null,
                        'bank_mfo' => $companyData['bankCode'] ?? null,
                        'director_name' => $companyData['director'] ?? null,
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
