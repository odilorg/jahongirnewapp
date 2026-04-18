<?php

declare(strict_types=1);

namespace App\Exceptions\Ledger;

/**
 * Thrown when any code attempts to UPDATE or DELETE a LedgerEntry row.
 *
 * The ledger is append-only by design. Adjustments and reversals are
 * NEW rows that reference the original via reverses_entry_id or
 * parent_entry_id — never in-place modifications of existing rows.
 *
 * The runtime write-firewall (L-018) extends this invariant by also
 * blocking direct LedgerEntry::create() outside the canonical
 * RecordLedgerEntry action.
 */
class LedgerImmutableException extends \RuntimeException
{
}
