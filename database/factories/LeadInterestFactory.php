<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadInterestFormat;
use App\Enums\LeadInterestStatus;
use App\Models\LeadInterest;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadInterestFactory extends Factory
{
    protected $model = LeadInterest::class;

    public function definition(): array
    {
        $directions = array_keys(config('leads.directions', []));

        return [
            'tour_freeform' => $this->faker->randomElement([
                'Classic Silk Road 7-day',
                'Samarkand day tour',
                'Nurata yurt camp overnight',
                'Bukhara old city walking tour',
            ]),
            'requested_date' => $this->faker->optional()->dateTimeBetween('+1 week', '+3 months'),
            'pax_adults'     => $this->faker->numberBetween(1, 6),
            'pax_children'   => $this->faker->numberBetween(0, 2),
            'format'         => $this->faker->randomElement(LeadInterestFormat::cases())->value,
            'direction_code' => $directions !== [] ? $this->faker->randomElement($directions) : null,
            'pickup_city'    => $this->faker->randomElement(['Samarkand', 'Bukhara', 'Tashkent']),
            'dropoff_city'   => $this->faker->randomElement(['Samarkand', 'Bukhara', 'Tashkent']),
            'status'         => LeadInterestStatus::Exploring->value,
        ];
    }
}
