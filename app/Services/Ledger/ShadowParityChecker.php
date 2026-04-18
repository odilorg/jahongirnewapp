<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\DTOs\Ledger\ShadowParityReport;
use App\Enums\CashTransactionSource;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Models\LedgerEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * L-006.5 — compare legacy cash_transactions rows against ledger_entries
 * rows in the same time window, per shadow source.
 *
 * The checker IS the trust gate between the old and new systems. While
 * the shadow flag is ON, every Beds24 payment webhook writes BOTH a
 * cash_transactions row AND a ledger_entries row. This service reads
 * both tables over a window, joins on the stable Beds24 item reference,
 * and classifies every discrepancy.
 *
 * CRITICAL RULE for L-006.5:
 *   Detect and measure. Do NOT auto-fix. Do NOT mutate data.
 *   Operators decide remediation once the report is read.
 */
final class ShadowParityChecker
{
    /**
     * Supported sources. Each key maps to:
     *   - legacy source_trigger value (cash_transactions.source_trigger)
     *   - ledger source enum value   (ledger_entries.source)
     *   - method-normalization mapper (so 'naqd'/'cash'/'наличные' are
     *     all the same PaymentMethod when compared)
     */
    private const SOURCES = [
        'beds24' => [
            'legacy_source_trigger' => 'beds24_external',
            'ledger_source'         => 'beds24_webhook',
        ],
    ];

    public function check(Carbon $from, Carbon $to, string $source = 'beds24'): ShadowParityReport
    {
        if (! isset(self::SOURCES[$source])) {
            throw new \InvalidArgumentException("Unknown parity source: {$source}");
        }

        return match ($source) {
            'beds24' => $this->checkBeds24($from, $to),
        };
    }

    // ---------------------------------------------------------------------

    private function checkBeds24(Carbon $from, Carbon $to): ShadowParityReport
    {
        $legacy = DB::table('cash_transactions')
            ->where('source_trigger', self::SOURCES['beds24']['legacy_source_trigger'])
            ->whereBetween('occurred_at', [$from, $to])
            ->get([
                'id',
                'beds24_booking_id',
                'beds24_payment_ref',
                'reference',
                'amount',
                'currency',
                'payment_method',
                'notes',
                'occurred_at',
            ]);

        $ledger = LedgerEntry::query()
            ->where('source', SourceTrigger::Beds24Webhook->value)
            ->whereBetween('occurred_at', [$from, $to])
            ->get([
                'id',
                'beds24_booking_id',
                'external_item_ref',
                'external_reference',
                'amount',
                'currency',
                'payment_method',
                'idempotency_key',
                'occurred_at',
            ]);

        // Build indexed maps on the join key.
        // Primary key:   (booking_id, item_ref like "b24_item_{id}")
        // Fallback key:  (booking_id, null)  — unmatchable without further context
        $legacyByKey   = [];
        $legacyNoKey   = [];
        foreach ($legacy as $row) {
            $itemRef = $row->beds24_payment_ref;
            if ($itemRef !== null) {
                $legacyByKey[$row->beds24_booking_id . '|' . $itemRef] = $row;
            } else {
                $legacyNoKey[] = $row;
            }
        }

        $ledgerByKey = [];
        $ledgerNoKey = [];
        foreach ($ledger as $row) {
            $itemRef = $row->external_item_ref;
            if ($itemRef !== null) {
                $ledgerByKey[$row->beds24_booking_id . '|' . $itemRef] = $row;
            } else {
                $ledgerNoKey[] = $row;
            }
        }

        $matchedKeys       = [];
        $missingLedger     = [];
        $extraLedger       = [];
        $amountMismatches  = [];
        $methodMismatches  = [];
        $currencyMismatches = [];

        // Walk legacy rows with keys.
        foreach ($legacyByKey as $key => $legacyRow) {
            if (! isset($ledgerByKey[$key])) {
                $missingLedger[] = $this->summariseLegacy($legacyRow, 'missing_in_ledger');
                continue;
            }

            $ledgerRow = $ledgerByKey[$key];
            $diff = $this->diff($legacyRow, $ledgerRow);

            if ($diff === null) {
                $matchedKeys[] = $key;
                continue;
            }

            // Categorise the discrepancy.
            if ($diff['reason'] === 'amount') {
                $amountMismatches[] = $diff;
            } elseif ($diff['reason'] === 'method') {
                $methodMismatches[] = $diff;
            } else {
                $currencyMismatches[] = $diff;
            }
        }

        // Any ledger key that does NOT appear in legacy is an extra row.
        foreach ($ledgerByKey as $key => $ledgerRow) {
            if (! isset($legacyByKey[$key])) {
                $extraLedger[] = $this->summariseLedger($ledgerRow, 'extra_in_ledger');
            }
        }

        // Unmatchable rows (no stable item ref on either side) — neither
        // MISSING nor EXTRA; ops must inspect manually.
        $unmatchable = [];
        foreach ($legacyNoKey as $r) {
            $unmatchable[] = $this->summariseLegacy($r, 'legacy_no_item_ref');
        }
        foreach ($ledgerNoKey as $r) {
            $unmatchable[] = $this->summariseLedger($r, 'ledger_no_item_ref');
        }

        return new ShadowParityReport(
            from:               $from,
            to:                 $to,
            source:             'beds24',
            legacyCount:        $legacy->count(),
            ledgerCount:        $ledger->count(),
            matchedKeys:        $matchedKeys,
            missingLedger:      $missingLedger,
            extraLedger:        $extraLedger,
            amountMismatches:   $amountMismatches,
            methodMismatches:   $methodMismatches,
            currencyMismatches: $currencyMismatches,
            unmatchableRows:    $unmatchable,
        );
    }

    /**
     * Compare a matched legacy/ledger pair. Return null on match,
     * otherwise an array describing the discrepancy.
     */
    private function diff(object $legacyRow, LedgerEntry $ledgerRow): ?array
    {
        if ($legacyRow->currency !== $ledgerRow->currency) {
            return [
                'key'       => $legacyRow->beds24_booking_id . '|' . $legacyRow->beds24_payment_ref,
                'reason'    => 'currency',
                'booking_id' => $legacyRow->beds24_booking_id,
                'item_ref'  => $legacyRow->beds24_payment_ref,
                'legacy'    => ['currency' => $legacyRow->currency],
                'ledger'    => ['currency' => $ledgerRow->currency],
            ];
        }

        $legacyAmount = number_format((float) $legacyRow->amount, 2, '.', '');
        $ledgerAmount = number_format((float) $ledgerRow->amount, 2, '.', '');
        if ($legacyAmount !== $ledgerAmount) {
            return [
                'key'        => $legacyRow->beds24_booking_id . '|' . $legacyRow->beds24_payment_ref,
                'reason'     => 'amount',
                'booking_id' => $legacyRow->beds24_booking_id,
                'item_ref'   => $legacyRow->beds24_payment_ref,
                'legacy'     => ['amount' => $legacyAmount],
                'ledger'     => ['amount' => $ledgerAmount],
            ];
        }

        // Normalise legacy method via the same mapping the adapter uses,
        // then compare with ledger's already-normalised method enum.
        $legacyNormalised = $this->normaliseBeds24Method((string) $legacyRow->payment_method);
        if ($legacyNormalised !== $ledgerRow->payment_method?->value) {
            return [
                'key'        => $legacyRow->beds24_booking_id . '|' . $legacyRow->beds24_payment_ref,
                'reason'     => 'method',
                'booking_id' => $legacyRow->beds24_booking_id,
                'item_ref'   => $legacyRow->beds24_payment_ref,
                'legacy'     => [
                    'raw'        => $legacyRow->payment_method,
                    'normalised' => $legacyNormalised,
                ],
                'ledger'     => ['payment_method' => $ledgerRow->payment_method?->value],
            ];
        }

        return null;
    }

    /**
     * Must stay in sync with Beds24PaymentAdapter::mapPaymentMethod().
     * When they diverge, the parity report will surface it as a
     * method_mismatch — which is exactly the bug this duplication traps.
     */
    private function normaliseBeds24Method(string $method): string
    {
        return match (mb_strtolower(trim($method))) {
            'cash', 'naqd', 'наличные'   => PaymentMethod::Cash->value,
            'card', 'карта'              => PaymentMethod::Card->value,
            'transfer', 'bank', 'перевод' => PaymentMethod::BankTransfer->value,
            default                      => PaymentMethod::Beds24External->value,
        };
    }

    private function summariseLegacy(object $row, string $marker): array
    {
        return [
            'marker'      => $marker,
            'legacy_id'   => $row->id,
            'booking_id'  => $row->beds24_booking_id,
            'item_ref'    => $row->beds24_payment_ref,
            'reference'   => $row->reference,
            'amount'      => number_format((float) $row->amount, 2, '.', ''),
            'currency'    => $row->currency,
            'method'      => $row->payment_method,
            'occurred_at' => (string) $row->occurred_at,
        ];
    }

    private function summariseLedger(LedgerEntry $row, string $marker): array
    {
        return [
            'marker'      => $marker,
            'ledger_id'   => $row->id,
            'booking_id'  => $row->beds24_booking_id,
            'item_ref'    => $row->external_item_ref,
            'external_reference' => $row->external_reference,
            'amount'      => number_format((float) $row->amount, 2, '.', ''),
            'currency'    => $row->currency,
            'method'      => $row->payment_method?->value,
            'occurred_at' => (string) $row->occurred_at,
        ];
    }
}
