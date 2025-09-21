<?php

namespace App\Enums;

enum TransactionCategory: string
{
    case SALE = 'sale';
    case REFUND = 'refund';
    case EXPENSE = 'expense';
    case DEPOSIT = 'deposit';
    case CHANGE = 'change';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Sale',
            self::REFUND => 'Refund',
            self::EXPENSE => 'Expense',
            self::DEPOSIT => 'Deposit',
            self::CHANGE => 'Change',
            self::OTHER => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SALE => 'success',
            self::REFUND => 'warning',
            self::EXPENSE => 'danger',
            self::DEPOSIT => 'info',
            self::CHANGE => 'gray',
            self::OTHER => 'secondary',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SALE => 'heroicon-o-shopping-cart',
            self::REFUND => 'heroicon-o-arrow-uturn-left',
            self::EXPENSE => 'heroicon-o-minus-circle',
            self::DEPOSIT => 'heroicon-o-plus-circle',
            self::CHANGE => 'heroicon-o-currency-dollar',
            self::OTHER => 'heroicon-o-question-mark-circle',
        };
    }
}

