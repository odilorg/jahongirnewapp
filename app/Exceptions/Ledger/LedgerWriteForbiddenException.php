<?php

declare(strict_types=1);

namespace App\Exceptions\Ledger;

/**
 * L-018 — thrown in ENFORCE mode when code attempts to create a
 * LedgerEntry outside an active LedgerWriteContext binding.
 *
 * Signals a discipline breach — code somewhere called
 * LedgerEntry::create() without going through the canonical
 * RecordLedgerEntry action. Distinct from LedgerImmutableException
 * (which blocks update/delete) so operators can distinguish "someone
 * tried to bypass the action" from "someone tried to mutate history".
 */
class LedgerWriteForbiddenException extends \RuntimeException
{
}
