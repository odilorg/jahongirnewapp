<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Flag on a ledger entry indicating how trustworthy the row is.
 *
 *  - ok:             written by a live flow through RecordLedgerEntry
 *  - backfilled:     one-time historical import from legacy tables
 *  - manual_review:  backfill or reconcile uncertain about pairing,
 *                    authorship, or amount — ops must inspect
 */
enum LedgerDataQuality: string
{
    case Ok            = 'ok';
    case Backfilled    = 'backfilled';
    case ManualReview  = 'manual_review';
}
