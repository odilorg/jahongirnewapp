<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

use App\Models\DailyExchangeRate;

/**
 * Formats a Beds24 booking's `price` for the cashier-bot guest-list
 * screen ("Оплата" → arrival-date list).
 *
 * Display-only; never used in payment math. The actual amount that the
 * cashier collects is resolved later via `BotPaymentService::preparePayment`
 * + `GroupAwareCashierAmountResolver`, both of which run their own FX
 * staleness guard via `FxStalenessGuard::ensureFreshOrFail`.
 *
 * Design notes:
 *  - Soft-degrades: when FX rate is null (stale or missing), returns
 *    USD-only formatting and lets the caller show a banner. NEVER throws.
 *  - Rounds UZS to nearest 1 000 for visual clarity. The rounded number
 *    is for the picker only — exact UZS quotation happens on the payment
 *    screen with the same rate.
 *  - Returns "—" for zero / missing / non-numeric prices. Never returns
 *    "$0" or "0 UZS" since that would mislead the cashier into thinking
 *    Beds24 actually has a 0-priced row (vs the much commoner case of
 *    a placeholder booking with no price set).
 */
final class GuestListPriceFormatter
{
    /**
     * UZS rounding bucket for the listing screen (informational only).
     * 1 000 UZS ≈ $0.08 — invisible at picker resolution.
     */
    private const UZS_ROUNDING_INCREMENT = 1000;

    /**
     * Format a booking's price for display.
     *
     * @param  array<string,mixed>  $booking  Beds24 booking row from getBookings.
     * @param  DailyExchangeRate|null  $rate  Today's rate, or null when
     *                                        FxStalenessGuard says stale/missing.
     */
    public function format(array $booking, ?DailyExchangeRate $rate): string
    {
        $raw = $booking['price'] ?? null;

        if ($raw === null || ! is_numeric($raw) || (float) $raw <= 0.0) {
            return '—';
        }

        $usd = (float) $raw;
        $currency = $booking['currency'] ?? 'USD';

        // Booking already in non-USD currency (rare today — both Jahongir
        // properties bill USD — but future-proof). Skip UZS conversion
        // since rate table is USD↔UZS only.
        if ($currency !== 'USD') {
            return $this->formatNative($usd, $currency);
        }

        $usdLabel = '$'.number_format($usd, 0);

        if ($rate === null) {
            return $usdLabel;
        }

        $uzsRate = (float) $rate->usd_uzs_rate;
        if ($uzsRate <= 0.0) {
            // Defensive: corrupt rate row. Soft-degrade rather than divide.
            return $usdLabel;
        }

        $uzs = $usd * $uzsRate;
        $uzsRounded = (int) (round($uzs / self::UZS_ROUNDING_INCREMENT) * self::UZS_ROUNDING_INCREMENT);
        $uzsLabel = number_format($uzsRounded, 0, '.', ' ').' UZS';

        return "{$uzsLabel} ({$usdLabel})";
    }

    private function formatNative(float $amount, string $currency): string
    {
        return match ($currency) {
            'EUR' => '€'.number_format($amount, 0),
            'UZS' => number_format($amount, 0, '.', ' ').' UZS',
            'RUB' => number_format($amount, 0, '.', ' ').' RUB',
            default => number_format($amount, 0).' '.$currency,
        };
    }
}
