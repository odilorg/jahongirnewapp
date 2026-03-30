<?php

namespace App\Enums;

enum CashTransactionSource: string
{
    case CashierBot     = 'cashier_bot';
    case Beds24External = 'beds24_external';
    case ManualAdmin    = 'manual_admin';

    /**
     * Whether this source contributes to the physical cash drawer balance.
     * beds24_external rows are bookkeeping signals, not drawer truth.
     */
    public function isDrawerTruth(): bool
    {
        return match($this) {
            self::CashierBot, self::ManualAdmin => true,
            self::Beds24External               => false,
        };
    }

    public function getLabel(): string
    {
        return match($this) {
            self::CashierBot     => 'Кассир (бот)',
            self::Beds24External => 'Beds24 (внешний)',
            self::ManualAdmin    => 'Администратор',
        };
    }
}
