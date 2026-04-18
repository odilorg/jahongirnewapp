<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ledger entries are single-leg: each row is either money in or money out.
 * A currency exchange is modelled as two rows (in + out) linked by
 * parent_entry_id, not as a single "in_out" row like the legacy
 * cash_transactions table.
 */
enum LedgerEntryDirection: string
{
    case In  = 'in';
    case Out = 'out';

    public function sign(): int
    {
        return match ($this) {
            self::In  => 1,
            self::Out => -1,
        };
    }
}
