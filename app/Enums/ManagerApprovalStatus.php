<?php

namespace App\Enums;

enum ManagerApprovalStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired  = 'expired';
    case Consumed = 'consumed'; // Linked to a cash_transaction — terminal, single-use

    public function isResolved(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Expired, self::Consumed]);
    }

    public function canBeConsumed(): bool
    {
        return $this === self::Approved;
    }
}
