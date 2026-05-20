<?php

namespace App\Services;

use App\Models\Turfirma;
use App\Models\Bank;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

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

            $mfo = $apiData['bankCode'] ?? $apiData['mfo'] ?? null;

            // Create a new Turfirma
            $turfirma = Turfirma::create([
                'name' => $apiData['shortName'] ?? null,
                'official_name' => $apiData['name'] ?? null,
                'address_street' => $apiData['address'] ?? null,
                'inn' => $apiData['tin'] ?? $data['tin'],
                'account_number' => $apiData['account'] ?? null,
                'bank_mfo' => $mfo,
                'bank_name' => self::resolveBankName($mfo),
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

    /**
     * Look up human-readable bank name by MFO. The `banks` table is optional
     * in this DB — when it's missing, return null rather than crashing the
     * whole Turfirma-create flow. Operator can fill bank name manually.
     */
    private static function resolveBankName(?string $mfo): ?string
    {
        if (! $mfo) {
            return null;
        }
        try {
            return Bank::where('mfo', $mfo)->value('bankName');
        } catch (Throwable $e) {
            Log::warning('TurfirmaService: bank name lookup skipped', [
                'mfo' => $mfo,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Returns the upstream tax-info payload normalized to didox shape:
     *   shortName, name, address, tin, account, mfo|bankCode, director (string).
     *
     * Calls go through the local loopback relay (autossh tunnel → Airnet UZ
     * VPS nginx → upstream). The relay holds the provider API keys; this app
     * never sees them. Direct outbound calls to gnk-api.didox.uz / soliq.uz
     * are blocked by Contabo egress, so no public-internet fallback here.
     */
    private static function fetchDataFromApis(string $tin): ?array
    {
        $relayUrl = config('services.tin_lookup.relay_url');
        if (! $relayUrl) {
            Log::warning('TurfirmaService: TIN_LOOKUP_RELAY_URL not configured; skipping auto-fetch', ['tin' => $tin]);
            return null;
        }

        // Order matters: didox first (richer payload — includes bank info +
        // director name as a string). Soliq is the fallback if didox is down.
        $endpoints = [
            'didox'  => rtrim($relayUrl, '/') . "/didox/info/{$tin}",
            'soliq'  => rtrim($relayUrl, '/') . "/company/info/{$tin}",
        ];

        foreach ($endpoints as $source => $url) {
            try {
                $response = Http::timeout(15)->connectTimeout(3)->get($url);
            } catch (Throwable $e) {
                Log::warning('TurfirmaService relay request failed', [
                    'source' => $source,
                    'tin' => $tin,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (! $response->successful()) {
                Log::info('TurfirmaService relay non-2xx', [
                    'source' => $source,
                    'tin' => $tin,
                    'status' => $response->status(),
                ]);
                continue;
            }

            $data = $response->json();
            if (! is_array($data)) {
                continue;
            }

            // didox: flat structure with shortName + name at top level.
            if (! empty($data['shortName']) && ! empty($data['name'])) {
                return $data;
            }

            // soliq: data wrapped under "company"; director is an object.
            // Normalize to didox-shape so the caller mapping stays uniform.
            if (! empty($data['company']['shortName']) && ! empty($data['company']['name'])) {
                $c = $data['company'];
                $dir = $data['director'] ?? null;
                return [
                    'tin'       => $c['tin'] ?? $tin,
                    'shortName' => $c['shortName'] ?? null,
                    'name'      => $c['name'] ?? null,
                    'address'   => $c['streetName'] ?? null,
                    'mfo'       => null,    // soliq endpoint does not return bank info
                    'account'   => null,
                    'director'  => is_array($dir)
                        ? trim(implode(' ', array_filter([
                            $dir['lastName'] ?? null,
                            $dir['firstName'] ?? null,
                            $dir['middleName'] ?? null,
                        ])))
                        : null,
                ];
            }
        }

        return null;
    }
}
