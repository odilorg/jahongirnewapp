<?php

namespace App\Enums;

enum Beds24SyncStatus: string
{
    case Pending   = 'pending';
    case Pushing   = 'pushing';   // Job is actively calling Beds24 API — prevents concurrent runs
    case Pushed    = 'pushed';    // API accepted; awaiting webhook confirmation
    case Confirmed = 'confirmed'; // Webhook matched and confirmed — terminal success
    case Failed    = 'failed';    // Max retries exhausted — needs ops attention
    case Skipped   = 'skipped';   // Not applicable (e.g. booking cancelled in Beds24)

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Failed, self::Skipped]);
    }

    public function canRetry(): bool
    {
        return $this === self::Failed;
    }
}
