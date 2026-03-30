<?php

namespace App\Enums;

enum OverrideTier: string
{
    case None    = 'none';
    case Cashier = 'cashier';
    case Manager = 'manager';
    case Blocked = 'blocked';

    public function requiresReason(): bool
    {
        return $this === self::Cashier;
    }

    public function requiresApproval(): bool
    {
        return $this === self::Manager;
    }

    public function isBlocked(): bool
    {
        return $this === self::Blocked;
    }

    /**
     * Whether the payment can proceed (possibly after reason/approval).
     */
    public function canProceed(): bool
    {
        return $this !== self::Blocked;
    }
}
