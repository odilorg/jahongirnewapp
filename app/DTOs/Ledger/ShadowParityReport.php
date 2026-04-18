<?php

declare(strict_types=1);

namespace App\DTOs\Ledger;

use Carbon\Carbon;

/**
 * L-006.5 — outcome of ShadowParityChecker::check().
 *
 * Shape designed to be serialisable to JSON for log shipping and
 * human-readable via the ledger:shadow-parity command.
 *
 * Each mismatch bucket holds an array of row summaries. The
 * summary shape is:
 *
 *   [
 *     'key'            => "bookingId|itemRef",
 *     'booking_id'     => string,
 *     'item_ref'       => string|null,
 *     'legacy'         => [...legacy snapshot...] (when applicable),
 *     'ledger'         => [...ledger snapshot...] (when applicable),
 *     'reason'         => 'amount'|'method'|'currency' (for *mismatches),
 *   ]
 */
final class ShadowParityReport
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $source,
        public readonly int    $legacyCount,
        public readonly int    $ledgerCount,
        /** @var list<string>                */ public readonly array $matchedKeys       = [],
        /** @var list<array<string, mixed>> */ public readonly array $missingLedger     = [],
        /** @var list<array<string, mixed>> */ public readonly array $extraLedger       = [],
        /** @var list<array<string, mixed>> */ public readonly array $amountMismatches  = [],
        /** @var list<array<string, mixed>> */ public readonly array $methodMismatches  = [],
        /** @var list<array<string, mixed>> */ public readonly array $currencyMismatches = [],
        /** @var list<array<string, mixed>> */ public readonly array $unmatchableRows   = [],
    ) {}

    public function matchedCount(): int
    {
        return count($this->matchedKeys);
    }

    public function driftCount(): int
    {
        return count($this->missingLedger)
            + count($this->extraLedger)
            + count($this->amountMismatches)
            + count($this->methodMismatches)
            + count($this->currencyMismatches);
    }

    public function hasDrift(): bool
    {
        return $this->driftCount() > 0;
    }

    public function matchRate(): float
    {
        $total = $this->legacyCount;
        if ($total === 0) {
            return 1.0;
        }
        return round($this->matchedCount() / $total, 4);
    }

    public function toArray(): array
    {
        return [
            'from'               => $this->from->toIso8601String(),
            'to'                 => $this->to->toIso8601String(),
            'source'             => $this->source,
            'legacy_count'       => $this->legacyCount,
            'ledger_count'       => $this->ledgerCount,
            'matched'            => $this->matchedCount(),
            'missing_ledger'     => count($this->missingLedger),
            'extra_ledger'       => count($this->extraLedger),
            'amount_mismatches'  => count($this->amountMismatches),
            'method_mismatches'  => count($this->methodMismatches),
            'currency_mismatches' => count($this->currencyMismatches),
            'unmatchable'        => count($this->unmatchableRows),
            'drift'              => $this->driftCount(),
            'match_rate'         => $this->matchRate(),
        ];
    }
}
