<?php

declare(strict_types=1);

namespace App\Events\Ledger;

use App\Models\LedgerEntry;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired exactly once per successful ledger write, inside the same
 * DB::transaction that created the row.
 *
 * Listeners live in app/Listeners/Ledger/ and drive projections
 * (balances, daily flow, guest-payment view, etc.). Registration
 * happens in L-005.
 */
final class LedgerEntryRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly LedgerEntry $entry,
    ) {}
}
