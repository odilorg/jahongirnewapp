<?php

namespace Database\Factories;

use App\Enums\CashTransactionSource;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashTransactionFactory extends Factory
{
    protected $model = CashTransaction::class;

    public function definition(): array
    {
        return [
            'type'               => TransactionType::IN->value,
            'amount'             => $this->faker->randomFloat(2, 10, 500),
            'currency'           => 'USD',
            'category'           => TransactionCategory::SALE->value,
            'source_trigger'     => CashTransactionSource::CashierBot->value,
            'beds24_booking_id'  => 'B' . $this->faker->numerify('######'),
            'payment_method'     => 'cash',
            'guest_name'         => $this->faker->name(),
            'occurred_at'        => now(),
        ];
    }
}
