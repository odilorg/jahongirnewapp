<?php

declare(strict_types=1);

namespace App\Exceptions\Ledger;

/**
 * Thrown when a LedgerEntryInput fails local/business-rule validation
 * before the ledger row is written.
 *
 * Examples:
 *  - amount <= 0
 *  - direction missing for an ambiguous entry_type
 *  - reverses_entry_id points at a row that does not exist
 *  - parent_entry_id references a row in a different currency
 */
class InvalidLedgerEntryException extends \InvalidArgumentException
{
}
