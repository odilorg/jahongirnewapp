<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches official exchange rates with a 3-source fallback chain.
 *
 * Priority:
 *   1. CBU (Central Bank of Uzbekistan) — official UZ government rate
 *   2. open.er-api.com                 — free, no key, ~150 currencies
 *   3. floatrates.com                   — free, no key, updates every 12h
 *
 * If all three fail, returns null so callers can fall back to manual entry.
 * All successful results are cached in Redis for 6 hours (rate is set once daily).
 *
 * Supports: USD/UZS (cashier bot), EUR/UZS (Beds24 registration form), RUB/UZS (same).
 */
class ExchangeRateService
{
    private const CACHE_KEY_USD = 'fx_usd_uzs_reference';
    private const CACHE_KEY_EUR = 'fx_eur_uzs_reference';
    private const CACHE_KEY_RUB = 'fx_rub_uzs_reference';
    private const CACHE_TTL     = 6 * 60 * 60; // 6 hours
    private const TIMEOUT       = 5;            // seconds per HTTP call

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the current USD→UZS reference rate.
     *
     * @return array{rate: float, source: string, effective_date: string, fetched_at: string}|null
     */
    public function getUsdToUzs(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY_USD);
        if ($cached) {
            return $cached;
        }

        $result = $this->fetchFromCbu('USD')
            ?? $this->fetchFromOpenErApi('USD')
            ?? $this->fetchFromFloatrates('USD');

        if ($result) {
            Cache::put(self::CACHE_KEY_USD, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Return the current EUR→UZS reference rate (1 EUR in UZS).
     *
     * @return array{rate: float, source: string, effective_date: string, fetched_at: string}|null
     */
    public function getEurToUzs(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY_EUR);
        if ($cached) {
            return $cached;
        }

        $result = $this->fetchFromCbu('EUR')
            ?? $this->fetchFromOpenErApi('EUR')
            ?? $this->fetchFromFloatrates('EUR');

        if ($result) {
            Cache::put(self::CACHE_KEY_EUR, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Return the current RUB→UZS reference rate (1 RUB in UZS).
     *
     * @return array{rate: float, source: string, effective_date: string, fetched_at: string}|null
     */
    public function getRubToUzs(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY_RUB);
        if ($cached) {
            return $cached;
        }

        $result = $this->fetchFromCbu('RUB')
            ?? $this->fetchFromOpenErApi('RUB')
            ?? $this->fetchFromFloatrates('RUB');

        if ($result) {
            Cache::put(self::CACHE_KEY_RUB, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Fetch all three rates in one call — used by the morning job.
     *
     * @return array{usd: array|null, eur: array|null, rub: array|null}
     */
    public function getAllRates(): array
    {
        return [
            'usd' => $this->getUsdToUzs(),
            'eur' => $this->getEurToUzs(),
            'rub' => $this->getRubToUzs(),
        ];
    }

    /**
     * Force-refresh the cached USD/UZS rate (artisan / admin panel).
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY_USD);
        return $this->getUsdToUzs();
    }

    /**
     * Force-refresh all cached rates.
     */
    public function refreshAll(): array
    {
        Cache::forget(self::CACHE_KEY_USD);
        Cache::forget(self::CACHE_KEY_EUR);
        Cache::forget(self::CACHE_KEY_RUB);
        return $this->getAllRates();
    }

    // -------------------------------------------------------------------------
    // Sources (all accept a currency code — defaults kept for back-compat)
    // -------------------------------------------------------------------------

    /**
     * Source 1: Central Bank of Uzbekistan
     * Endpoint: https://cbu.uz/en/arkhiv-kursov-valyut/json/{CURRENCY}/
     * Response: [{"Rate":"12179.87","Date":"27.03.2026", ...}]
     *
     * For RUB the CBU publishes the rate for 1 RUB (very small decimal), not per 100.
     */
    private function fetchFromCbu(string $currency = 'USD'): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get("https://cbu.uz/en/arkhiv-kursov-valyut/json/{$currency}/");

            if (!$response->successful()) {
                Log::warning("ExchangeRateService: CBU [{$currency}] returned non-200", ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $row  = $data[0] ?? null;

            if (!$row || empty($row['Rate'])) {
                Log::warning("ExchangeRateService: CBU [{$currency}] response missing Rate field");
                return null;
            }

            $rate = (float) $row['Rate'];
            if ($rate <= 0) return null;

            // Convert "27.03.2026" → "2026-03-27"
            $dateRaw       = $row['Date'] ?? now()->format('d.m.Y');
            $effectiveDate = $this->parseCbuDate($dateRaw);

            return $this->dto($rate, 'cbu', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning("ExchangeRateService: CBU [{$currency}] fetch failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Source 2: open.er-api.com
     * Endpoint: https://open.er-api.com/v6/latest/USD
     * Response: {"rates": {"UZS": 12195, "EUR": 0.921, ...}, "time_last_update_utc": "..."}
     *
     * All rates are expressed as 1 USD = X target, so for EUR/RUB we need
     * to compute: 1 EUR/RUB in UZS = UZS_rate / EUR_rate.
     */
    private function fetchFromOpenErApi(string $currency = 'USD'): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get('https://open.er-api.com/v6/latest/USD');

            if (!$response->successful()) {
                Log::warning("ExchangeRateService: open.er-api returned non-200", ['status' => $response->status()]);
                return null;
            }

            $data    = $response->json();
            $rates   = $data['rates'] ?? [];
            $uzsRate = (float) ($rates['UZS'] ?? 0);

            if ($uzsRate <= 0) {
                Log::warning('ExchangeRateService: open.er-api missing UZS rate');
                return null;
            }

            if ($currency === 'USD') {
                $rate = $uzsRate;
            } else {
                // Convert: 1 EUR = (1/EUR_per_USD) USD = UZS_per_USD / EUR_per_USD UZS
                $currencyRate = (float) ($rates[$currency] ?? 0);
                if ($currencyRate <= 0) {
                    Log::warning("ExchangeRateService: open.er-api missing {$currency} rate");
                    return null;
                }
                $rate = $uzsRate / $currencyRate;
            }

            $effectiveDate = $this->parseRfc2822Date($data['time_last_update_utc'] ?? '');

            return $this->dto($rate, 'er_api', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning("ExchangeRateService: open.er-api [{$currency}] fetch failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Source 3: floatrates.com
     * Endpoint: https://www.floatrates.com/daily/usd.json
     * Response: {"uzs": {"rate": 12181.19, ...}, "eur": {...}, "rub": {...}}
     *
     * All entries are expressed as 1 USD = X target — same cross-rate math needed.
     */
    private function fetchFromFloatrates(string $currency = 'USD'): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get('https://www.floatrates.com/daily/usd.json');

            if (!$response->successful()) {
                Log::warning("ExchangeRateService: floatrates returned non-200", ['status' => $response->status()]);
                return null;
            }

            $data    = $response->json();
            $uzsData = $data['uzs'] ?? null;
            $uzsRate = (float) ($uzsData['rate'] ?? 0);

            if ($uzsRate <= 0) {
                Log::warning('ExchangeRateService: floatrates missing UZS rate');
                return null;
            }

            if ($currency === 'USD') {
                $rate          = $uzsRate;
                $effectiveDate = $this->parseRfc2822Date($uzsData['date'] ?? '');
            } else {
                $currencyKey  = strtolower($currency);
                $currencyData = $data[$currencyKey] ?? null;
                $currencyRate = (float) ($currencyData['rate'] ?? 0);

                if ($currencyRate <= 0) {
                    Log::warning("ExchangeRateService: floatrates missing {$currency} rate");
                    return null;
                }

                $rate          = $uzsRate / $currencyRate;
                $effectiveDate = $this->parseRfc2822Date($currencyData['date'] ?? '');
            }

            return $this->dto($rate, 'floatrates', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning("ExchangeRateService: floatrates [{$currency}] fetch failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{rate: float, source: string, effective_date: string, fetched_at: string}
     */
    private function dto(float $rate, string $source, string $effectiveDate): array
    {
        return [
            'rate'           => round($rate, 2),
            'source'         => $source,
            'effective_date' => $effectiveDate,
            'fetched_at'     => now()->toIso8601String(),
        ];
    }

    /** "27.03.2026" → "2026-03-27" */
    private function parseCbuDate(string $raw): string
    {
        try {
            return \Carbon\Carbon::createFromFormat('d.m.Y', $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    /** RFC-2822 string → "Y-m-d" */
    private function parseRfc2822Date(string $raw): string
    {
        if (!$raw) return now()->format('Y-m-d');
        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    /** Human-readable source label for bot messages */
    public function sourceLabel(string $source): string
    {
        return match ($source) {
            'cbu'        => 'ЦБ УЗ',
            'er_api'     => 'ExchangeRate-API',
            'floatrates' => 'FloatRates',
            default      => 'Внешний источник',
        };
    }
}
