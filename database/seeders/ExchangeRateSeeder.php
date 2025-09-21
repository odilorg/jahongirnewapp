<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rates = [
            // UZS to EUR (1 UZS = 0.000092 EUR)
            [
                'from_currency' => Currency::UZS,
                'to_currency' => Currency::EUR,
                'rate' => 0.000092,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
            // UZS to USD (1 UZS = 0.00010 USD)
            [
                'from_currency' => Currency::UZS,
                'to_currency' => Currency::USD,
                'rate' => 0.00010,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
            // UZS to RUB (1 UZS = 0.0092 RUB)
            [
                'from_currency' => Currency::UZS,
                'to_currency' => Currency::RUB,
                'rate' => 0.0092,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
            // Reverse rates
            // EUR to UZS (1 EUR = 10869.57 UZS)
            [
                'from_currency' => Currency::EUR,
                'to_currency' => Currency::UZS,
                'rate' => 10869.57,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
            // USD to UZS (1 USD = 10000 UZS)
            [
                'from_currency' => Currency::USD,
                'to_currency' => Currency::UZS,
                'rate' => 10000,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
            // RUB to UZS (1 RUB = 108.70 UZS)
            [
                'from_currency' => Currency::RUB,
                'to_currency' => Currency::UZS,
                'rate' => 108.70,
                'effective_date' => now(),
                'is_active' => true,
                'notes' => 'Approximate rate - update with real rates',
            ],
        ];

        foreach ($rates as $rate) {
            ExchangeRate::create($rate);
        }

        $this->command->info('✅ Exchange rates seeded successfully!');
        $this->command->info('📊 Supported currencies: UZS, EUR, USD, RUB');
    }
}

