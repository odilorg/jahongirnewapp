<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * L-018 — container marker object indicating "we are inside a
 * sanctioned ledger write path".
 *
 * Bound into the Laravel service container by RecordLedgerEntry at
 * the start of its DB transaction and unbound on exit. The model-side
 * firewall hook on LedgerEntry checks for this binding before allowing
 * a `creating` event to proceed:
 *
 *   mode=off     — never checks
 *   mode=warn    — logs a warning when writes happen outside a context
 *   mode=enforce — throws LedgerWriteForbiddenException outside a context
 *
 * Future writers (L-015 backfill, ad-hoc maintenance scripts) construct
 * their own context with a distinct initiator so logs identify who
 * wrote what.
 */
final class LedgerWriteContext
{
    public function __construct(
        /** Free-form identifier — "RecordLedgerEntry", "LedgerBackfill", "MaintenanceScript" */
        public readonly string $initiator,
        public readonly ?int   $userId = null,
    ) {}
}
