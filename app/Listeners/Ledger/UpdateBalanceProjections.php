<?php

declare(strict_types=1);

namespace App\Listeners\Ledger;

use App\Events\Ledger\LedgerEntryRecorded;
use App\Services\Ledger\BalanceProjectionUpdater;

/**
 * L-005 synchronous listener.
 *
 * Fired inside the ledger's DB::transaction (see RecordLedgerEntry).
 * This means balance projections are updated in the SAME atomic
 * boundary as the ledger row itself — a caller that has a successful
 * ledger row is guaranteed to also see the balance movement.
 *
 * Runs synchronously on purpose; single-row balance updates are cheap
 * and must never lag behind the write. Aggregate/heavy projections
 * (daily flow, monthly report cache) belong in UpdateAggregateProjections
 * later (L-013) and should be queued.
 */
final class UpdateBalanceProjections
{
    public function __construct(
        private readonly BalanceProjectionUpdater $updater,
    ) {}

    public function handle(LedgerEntryRecorded $event): void
    {
        $this->updater->apply($event->entry);
    }
}
