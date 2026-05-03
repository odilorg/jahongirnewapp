<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

use App\Enums\CashTransactionSource;
use App\Models\CashTransaction;
use Illuminate\Support\Collection;

/**
 * Detects prior payments for a Beds24 booking across both ingestion
 * sources (cashier_bot and beds24_external) and classifies severity:
 *
 *   - 'duplicate'  — prior row exists with SAME currency + amount within
 *                    tolerance → high duplicate suspicion (🚨)
 *   - 'split'      — prior row exists but amount differs (deposit + balance,
 *                    multi-currency partial, etc.) → soft warning (⚠)
 *   - null         — no prior payment, no warning needed
 *
 * Soft delete-aware: Eloquent's default scope excludes soft-deleted rows,
 * so an admin who soft-deletes a duplicate via Filament will not retrigger
 * the warning on a fresh attempt.
 *
 * Whether to BLOCK the payment is the controller's call, not this
 * detector's — current product policy is "warn, never hard-block"
 * because real-world flows include legitimate dual-source payments
 * (Beds24 deposit + cashier balance).
 */
class PriorPaymentDetector
{
    /**
     * Tolerance for "same amount" comparison. Exact match for currencies
     * with whole-unit conventions (UZS); the float epsilon catches
     * USD/EUR rounding drift between Beds24's recorded amount and the
     * cashier's typed figure.
     */
    private const AMOUNT_TOLERANCE = 1.0;

    public function detect(int $beds24BookingId, float $newAmount, string $newCurrency): ?array
    {
        $priors = CashTransaction::query()
            ->where('beds24_booking_id', $beds24BookingId)
            ->whereIn('source_trigger', [
                CashTransactionSource::CashierBot->value,
                CashTransactionSource::Beds24External->value,
            ])
            ->orderBy('id')
            ->get();

        if ($priors->isEmpty()) {
            return null;
        }

        $duplicate = $priors->first(function (CashTransaction $tx) use ($newAmount, $newCurrency) {
            if (strcasecmp($tx->currency, $newCurrency) !== 0) {
                return false;
            }
            return abs((float) $tx->amount - $newAmount) <= self::AMOUNT_TOLERANCE;
        });

        return [
            'tier'   => $duplicate ? 'duplicate' : 'split',
            'priors' => $priors,
        ];
    }

    /**
     * Format a Russian operator-facing warning line for the confirm screen.
     * Returns empty string when no warning applies.
     */
    public function formatWarning(?array $detection, int $beds24BookingId): string
    {
        if ($detection === null) {
            return '';
        }

        /** @var Collection<int, CashTransaction> $priors */
        $priors = $detection['priors'];
        $first  = $priors->first();

        $sourceLabel = match ((string) $first->source_trigger?->value ?: (string) $first->source_trigger) {
            CashTransactionSource::CashierBot->value     => 'Cashier Bot',
            CashTransactionSource::Beds24External->value => 'Beds24',
            default                                      => 'другая система',
        };

        $whenLabel = $first->occurred_at?->format('d.m H:i')
            ?? optional($first->created_at)->format('d.m H:i')
            ?? '?';

        $methodLabel = match ((string) $first->payment_method) {
            'cash', 'naqd' => 'наличные',
            'card', 'karta' => 'карта',
            'transfer' => 'перевод',
            ''  => '—',
            default => (string) $first->payment_method,
        };

        $amountLabel = number_format((float) $first->amount, 0, '.', ' ') . ' ' . $first->currency;

        if ($detection['tier'] === 'duplicate') {
            return "\n\n🚨 <b>ВНИМАНИЕ:</b> Похожий платеж уже существует.\n"
                . "Beds24 #{$beds24BookingId} · {$sourceLabel} · {$whenLabel} · {$amountLabel} · {$methodLabel}\n"
                . 'Проверьте, не является ли это дублем.';
        }

        // Tier 'split' — prior payment with different amount (likely top-up)
        return "\n\n⚠ В системе уже записан платеж по этой брони:\n"
            . "Beds24 #{$beds24BookingId} · {$sourceLabel} · {$whenLabel} · {$amountLabel} · {$methodLabel}\n"
            . 'Записываю как отдельную транзакцию.';
    }
}
