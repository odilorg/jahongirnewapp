<?php

namespace App\Services;

use App\Models\Turfirma;
use App\Models\Bank;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class TurfirmaService
{
    public static function createOrFetchTurfirma(array $data): int
    {
        // Check if the company already exists
        if (!empty($data['tin'])) {
            $existingTurfirma = Turfirma::where('inn', $data['tin'])->first();

            if ($existingTurfirma) {
                Notification::make()
                    ->title('Duplicate Entry')
                    ->body('A company with this TIN already exists.')
                    ->success()
                    ->send();

                return $existingTurfirma->id;
            }

            // Fetch data from APIs
            $apiData = self::fetchDataFromApis($data['tin']);

            if (!$apiData) {
                Notification::make()
                    ->title('Error Fetching Data')
                    ->body('All APIs are down, or the TIN is invalid. Please add the company details manually.')
                    ->danger()
                    ->send();

                throw ValidationException::withMessages([
                    'tin' => 'Failed to fetch data. Verify TIN or add manually.',
                ]);
            }

            // Create a new Turfirma
            $turfirma = Turfirma::create([
                'name' => $apiData['shortName'] ?? null,
                'official_name' => $apiData['name'] ?? null,
                'address_street' => $apiData['address'] ?? null,
                'inn' => $apiData['tin'] ?? $data['tin'],
                'account_number' => $apiData['account'] ?? null,
                'bank_mfo' => $apiData['bankCode'] ?? $apiData['mfo'] ?? null,
                'bank_name' => Bank::where('mfo', $apiData['bankCode'] ?? $apiData['mfo'] ?? null)->value('bankName'),
                'director_name' => $apiData['director'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'],
                'type' => $data['type'],
                'api_data' => json_encode($apiData),
            ]);

            // Success notification
            Notification::make()
                ->title('Company Created')
                ->body("The company '{$turfirma->name}' has been successfully created.")
                ->success()
                ->send();

            return $turfirma->id;
        }

        // If no TIN, create minimal Turfirma
        $turfirma = Turfirma::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'type' => $data['type'],
        ]);

        // Success notification for minimal Turfirma creation
        Notification::make()
            ->title('Tourfirm Created')
            ->body("The company '{$turfirma->name}' has been successfully created.")
            ->success()
            ->send();

        return $turfirma->id;
    }

    private static function fetchDataFromApis(string $tin): ?array
    {
        $urls = [
            "https://gnk-api.didox.uz/api/v1/utils/info/{$tin}",
            "https://new.soliqservis.uz/api/np1/bytin/factura?tinOrPinfl={$tin}",
            "https://stage.goodsign.biz/v1/utils/info/{$tin}",
        ];

        foreach ($urls as $url) {
            $response = Http::get($url);

            if ($response->successful() && !empty($response->json('shortName')) && !empty($response->json('name'))) {
                return $response->json();
            }
        }

        return null;
    }
}
