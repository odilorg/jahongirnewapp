<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

use App\Models\DailyExchangeRate;
use App\Services\BookingPaymentOptionsService;

/**
 * Formats a Beds24 booking's payable amount for the cashier-bot
 * guest-list screen ("Оплата" → arrival-date list).
 *
 * Display-only; never used in payment math. The actual amount the
 * cashier collects is resolved later via
 * `BotPaymentService::preparePayment` +
 * `GroupAwareCashierAmountResolver`, both of which run their own
 * FX staleness guard via `FxStalenessGuard::ensureFreshOrFail`.
 *
 * # Amount semantics — matches the payment-screen path
 *
 * "USD to collect" is the booking's OUTSTANDING balance, not the
 * total. Calculated as `booking.price − Σ(negative invoiceItems)`
 * (Beds24 stores payments as negative invoiceItems).
 *
 * The total is also shown when the booking is partially paid so the
 * cashier sees both numbers at a glance:
 *
 *   no payments yet   → "к оплате: 1 454 000 UZS ($120)"
 *   partial payment   → "к оплате: 727 000 UZS ($60 из $120)"
 *   fully paid        → "оплачено: 1 454 000 UZS ($120)"
 *   missing price     → "—"
 *
 * # Rounding — matches BookingPaymentOptionsService
 *
 * Uses `BookingPaymentOptionsService::ceilToIncrement` with the
 * `daily_exchange_rates.uzs_rounding_increment` from the rate row
 * (typically 1 000). This guarantees that the UZS number shown on
 * the listing screen IS the exact UZS number the payment screen
 * will quote for the same USD — no half-up vs ceil drift, no
 * hard-coded increment that could diverge from the DB column.
 *
 * # Soft-degrade
 *
 * - missing/null/zero/non-numeric price → "—"
 * - non-USD booking currency → native formatting, no UZS conversion
 * - missing invoiceItems → fall back to total as outstanding (safe
 *   default for older response shapes or rows where Beds24 omitted
 *   the field)
 * - rate stale/missing → USD-only labels (listing never refuses)
 * - corrupt rate row (`usd_uzs_rate <= 0`) → USD-only labels
 */
final class GuestListPriceFormatter
{
    public function __construct(
        private readonly BookingPaymentOptionsService $paymentOptions,
    ) {}

    /**
     * @param  array<string,mixed>  $booking  Beds24 booking row from getBookings.
     *                                        Should include `invoiceItems` for
     *                                        accurate outstanding calculation;
     *                                        without it, total is used as fallback.
     * @param  DailyExchangeRate|null  $rate  Today's rate, or null when
     *                                        FxStalenessGuard says stale/missing.
     */
    public function format(array $booking, ?DailyExchangeRate $rate): string
    {
        $rawTotal = $booking['price'] ?? null;
        if ($rawTotal === null || ! is_numeric($rawTotal) || (float) $rawTotal <= 0.0) {
            return '—';
        }

        $totalUsd = (float) $rawTotal;
        $outstandingUsd = $this->computeOutstandingUsd($booking, $totalUsd);
        $currency = $booking['currency'] ?? 'USD';

        // Non-USD bookings: skip UZS conversion (rate table is USD↔UZS
        // only). Future-proof — both Jahongir properties bill USD today.
        if ($currency !== 'USD') {
            return $this->formatNative($outstandingUsd, $totalUsd, $currency);
        }

        if ($outstandingUsd <= 0.0) {
            return $this->formatFullyPaid($totalUsd, $rate);
        }

        $isPartial = $outstandingUsd < $totalUsd;

        return $this->formatPayable($outstandingUsd, $totalUsd, $isPartial, $rate);
    }

    /**
     * Outstanding = price − Σ(|negative invoiceItems|). Beds24 stores
     * payment lines as negative-amount invoiceItems; charges are positive.
     * Mirrors `GroupAwareCashierAmountResolver::extractAmountFromApiResponse`
     * so listing and payment-prep agree on what "outstanding" means.
     */
    private function computeOutstandingUsd(array $booking, float $totalUsd): float
    {
        $items = $booking['invoiceItems'] ?? null;
        if (! is_array($items)) {
            // No invoiceItems in response — older payload shape, or the
            // listing-fetch didn't request them. Treat as no payments yet.
            return $totalUsd;
        }

        $paid = 0.0;
        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            if ($amount < 0) {
                $paid += abs($amount);
            }
        }

        return $totalUsd - $paid;
    }

    private function formatPayable(
        float $outstandingUsd,
        float $totalUsd,
        bool $isPartial,
        ?DailyExchangeRate $rate,
    ): string {
        $usdOutstanding = '$'.number_format($outstandingUsd, 0);
        $usdTotal = '$'.number_format($totalUsd, 0);

        $usdContext = $isPartial
            ? "{$usdOutstanding} из {$usdTotal}"
            : $usdOutstanding;

        $uzsLabel = $this->formatUzsOrNull($outstandingUsd, $rate);
        if ($uzsLabel === null) {
            return "к оплате: {$usdContext}";
        }

        return "к оплате: {$uzsLabel} ({$usdContext})";
    }

    private function formatFullyPaid(float $totalUsd, ?DailyExchangeRate $rate): string
    {
        $usdTotal = '$'.number_format($totalUsd, 0);
        $uzsLabel = $this->formatUzsOrNull($totalUsd, $rate);

        if ($uzsLabel === null) {
            return "оплачено: {$usdTotal}";
        }

        return "оплачено: {$uzsLabel} ({$usdTotal})";
    }

    private function formatNative(float $outstandingNative, float $totalNative, string $currency): string
    {
        $outLabel = $this->renderNative($outstandingNative, $currency);
        $totalLabel = $this->renderNative($totalNative, $currency);
        $isPartial = $outstandingNative < $totalNative && $outstandingNative > 0.0;
        $fullyPaid = $outstandingNative <= 0.0;

        if ($fullyPaid) {
            return "оплачено: {$totalLabel}";
        }

        return $isPartial
            ? "к оплате: {$outLabel} (из {$totalLabel})"
            : "к оплате: {$outLabel}";
    }

    /**
     * Return a "1 454 000 UZS" label using the same ceil-to-increment
     * rounding the payment-prep path uses (single source of truth via
     * `BookingPaymentOptionsService::ceilToIncrement`). Reads the
     * configurable `uzs_rounding_increment` from the rate row so an
     * operator change to that column applies uniformly to listing and
     * payment screens.
     *
     * Returns null when conversion is unavailable (stale/null rate or
     * corrupt `usd_uzs_rate`), letting the caller render USD-only.
     */
    private function formatUzsOrNull(float $usdAmount, ?DailyExchangeRate $rate): ?string
    {
        if ($rate === null) {
            return null;
        }

        $usdUzsRate = (float) $rate->usd_uzs_rate;
        if ($usdUzsRate <= 0.0) {
            return null;
        }

        $increment = (int) $rate->uzs_rounding_increment;
        $uzsExact = $usdAmount * $usdUzsRate;
        $uzsFinal = $this->paymentOptions->ceilToIncrement($uzsExact, $increment);

        return number_format($uzsFinal, 0, '.', ' ').' UZS';
    }

    private function renderNative(float $amount, string $currency): string
    {
        return match ($currency) {
            'EUR' => '€'.number_format($amount, 0),
            'UZS' => number_format($amount, 0, '.', ' ').' UZS',
            'RUB' => number_format($amount, 0, '.', ' ').' RUB',
            default => number_format($amount, 0).' '.$currency,
        };
    }
}
