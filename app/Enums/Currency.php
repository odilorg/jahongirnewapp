<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Currency: string implements HasLabel, HasColor
{
    case UZS = 'UZS';
    case EUR = 'EUR';
    case USD = 'USD';
    case RUB = 'RUB';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::UZS => 'Uzbek Som (UZS)',
            self::EUR => 'Euro (EUR)',
            self::USD => 'US Dollar (USD)',
            self::RUB => 'Russian Ruble (RUB)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::UZS => Color::Blue,
            self::EUR => Color::Green,
            self::USD => Color::Orange,
            self::RUB => Color::Red,
        };
    }

    public function getSymbol(): string
    {
        return match ($this) {
            self::UZS => 'UZS',
            self::EUR => '€',
            self::USD => '$',
            self::RUB => '₽',
        };
    }

    public function getDecimalPlaces(): int
    {
        return match ($this) {
            self::UZS => 2,
            self::EUR => 2,
            self::USD => 2,
            self::RUB => 2,
        };
    }

    public function getDefaultExchangeRate(): float
    {
        return match ($this) {
            self::UZS => 1.0, // Base currency
            self::EUR => 0.000092, // 1 UZS = 0.000092 EUR (approximate)
            self::USD => 0.00010, // 1 UZS = 0.00010 USD (approximate)
            self::RUB => 0.0092, // 1 UZS = 0.0092 RUB (approximate)
        };
    }

    public function formatAmount(float $amount): string
    {
        $symbol = $this->getSymbol();
        $formatted = number_format($amount, $this->getDecimalPlaces());
        
        return match ($this) {
            self::UZS => "{$formatted} {$symbol}",
            self::EUR => "{$symbol}{$formatted}",
            self::USD => "{$symbol}{$formatted}",
            self::RUB => "{$formatted} {$symbol}",
        };
    }
}
