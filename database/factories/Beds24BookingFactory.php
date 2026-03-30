<?php

namespace Database\Factories;

use App\Models\Beds24Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

class Beds24BookingFactory extends Factory
{
    protected $model = Beds24Booking::class;

    public function definition(): array
    {
        return [
            'beds24_booking_id' => 'B' . $this->faker->unique()->numerify('######'),
            'guest_name'        => $this->faker->name(),
            'guest_email'       => $this->faker->safeEmail(),
            'room_name'         => '10' . $this->faker->numberBetween(1, 9),
            'arrival_date'      => now()->addDays(1)->toDateString(),
            'departure_date'    => now()->addDays(3)->toDateString(),
            'num_adults'        => 2,
            'total_amount'      => $this->faker->randomFloat(2, 50, 500),
            'currency'          => 'USD',
            'invoice_balance'   => 0,
            'payment_status'    => 'paid',
            'booking_status'    => 'confirmed',
        ];
    }
}
