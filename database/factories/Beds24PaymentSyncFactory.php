<?php

namespace Database\Factories;

use App\Enums\Beds24SyncStatus;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class Beds24PaymentSyncFactory extends Factory
{
    protected $model = Beds24PaymentSync::class;

    public function definition(): array
    {
        return [
            // beds24_payment_syncs.cash_transaction_id is NOT NULL with a
            // FK to cash_transactions; auto-create the parent so the
            // factory works in isolation.
            'cash_transaction_id' => CashTransaction::factory(),
            'beds24_booking_id'   => 'B' . $this->faker->numerify('######'),
            'local_reference'     => Str::uuid()->toString(),
            'amount_usd'          => $this->faker->randomFloat(2, 10, 500),
            'status'              => Beds24SyncStatus::Pending->value,
        ];
    }
}
