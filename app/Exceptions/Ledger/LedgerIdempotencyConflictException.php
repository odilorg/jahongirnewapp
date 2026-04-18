<?php

declare(strict_types=1);

namespace App\Exceptions\Ledger;

use App\Models\LedgerEntry;

/**
 * Thrown when RecordLedgerEntry is called with an idempotency_key that
 * already exists for the same source, but the payload differs from what
 * was previously stored.
 *
 * Silent idempotent replay (identical payload) returns the existing row.
 * A true conflict (same key, different data) is a bug in the caller and
 * must surface loudly — a refund replay with a different amount, a
 * webhook retry with a modified payload, etc.
 */
class LedgerIdempotencyConflictException extends \RuntimeException
{
    public function __construct(
        public readonly LedgerEntry $existing,
        public readonly array       $differences,
        string                      $message = '',
    ) {
        if ($message === '') {
            $message = sprintf(
                'LedgerEntry idempotency conflict: source=%s key=%s already stored with different payload (%d field(s) differ)',
                $existing->source?->value ?? 'unknown',
                $existing->idempotency_key ?? 'null',
                count($differences),
            );
        }
        parent::__construct($message);
    }
}
