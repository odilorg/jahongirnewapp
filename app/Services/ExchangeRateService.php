<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the official USD/UZS reference rate with a 3-source fallback chain.
 *
 * Priority:
 *   1. CBU (Central Bank of Uzbekistan) — official UZ government rate
 *   2. open.er-api.com                 — free, no key, ~150 currencies
 *   3. floatrates.com                   — free, no key, updates every 12h
 *
 * If all three fail, returns null so callers can fall back to manual entry.
 * All successful results are cached in Redis for 6 hours (rate is set once daily).
 */
class ExchangeRateService
{
    private const CACHE_KEY    = 'fx_usd_uzs_reference';
    private const CACHE_TTL    = 6 * 60 * 60; // 6 hours
    private const TIMEOUT      = 5;            // seconds per HTTP call

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
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $result = $this->fetchFromCbu()
            ?? $this->fetchFromOpenErApi()
            ?? $this->fetchFromFloatrates();

        if ($result) {
            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Force-refresh the cached rate (call from artisan or admin panel if needed).
     */
    public function refresh(): ?array
    {
        Cache::forget(self::CACHE_KEY);
        return $this->getUsdToUzs();
    }

    // -------------------------------------------------------------------------
    // Sources
    // -------------------------------------------------------------------------

    /**
     * Source 1: Central Bank of Uzbekistan
     * Endpoint: https://cbu.uz/en/arkhiv-kursov-valyut/json/USD/
     * Response: [{"Rate":"12179.87","Date":"27.03.2026", ...}]
     */
    private function fetchFromCbu(): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get('https://cbu.uz/en/arkhiv-kursov-valyut/json/USD/');

            if (!$response->successful()) {
                Log::warning('ExchangeRateService: CBU returned non-200', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $row  = $data[0] ?? null;

            if (!$row || empty($row['Rate'])) {
                Log::warning('ExchangeRateService: CBU response missing Rate field');
                return null;
            }

            $rate = (float) $row['Rate'];
            if ($rate <= 0) return null;

            // Convert "27.03.2026" → "2026-03-27"
            $dateRaw       = $row['Date'] ?? now()->format('d.m.Y');
            $effectiveDate = $this->parseCbuDate($dateRaw);

            return $this->dto($rate, 'cbu', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService: CBU fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Source 2: open.er-api.com
     * Endpoint: https://open.er-api.com/v6/latest/USD
     * Response: {"rates": {"UZS": 12195.185701, ...}, "time_last_update_utc": "..."}
     */
    private function fetchFromOpenErApi(): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get('https://open.er-api.com/v6/latest/USD');

            if (!$response->successful()) {
                Log::warning('ExchangeRateService: open.er-api returned non-200', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $rate = (float) ($data['rates']['UZS'] ?? 0);

            if ($rate <= 0) {
                Log::warning('ExchangeRateService: open.er-api missing UZS rate');
                return null;
            }

            // "time_last_update_utc" → "Sat, 28 Mar 2026 00:15:41 +0000"
            $effectiveDate = $this->parseRfc2822Date($data['time_last_update_utc'] ?? '');

            return $this->dto($rate, 'er_api', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService: open.er-api fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Source 3: floatrates.com
     * Endpoint: https://www.floatrates.com/daily/usd.json
     * Response: {"uzs": {"rate": 12181.193019015, "date": "Fri, 27 Mar 2026 22:55:04 GMT"}, ...}
     */
    private function fetchFromFloatrates(): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get('https://www.floatrates.com/daily/usd.json');

            if (!$response->successful()) {
                Log::warning('ExchangeRateService: floatrates returned non-200', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $uzs  = $data['uzs'] ?? null;
            $rate = (float) ($uzs['rate'] ?? 0);

            if ($rate <= 0) {
                Log::warning('ExchangeRateService: floatrates missing UZS rate');
                return null;
            }

            $effectiveDate = $this->parseRfc2822Date($uzs['date'] ?? '');

            return $this->dto($rate, 'floatrates', $effectiveDate);

        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService: floatrates fetch failed', ['error' => $e->getMessage()]);
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
