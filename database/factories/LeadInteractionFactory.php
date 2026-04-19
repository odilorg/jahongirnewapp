<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Models\LeadInteraction;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadInteractionFactory extends Factory
{
    protected $model = LeadInteraction::class;

    public function definition(): array
    {
        return [
            'channel'      => $this->faker->randomElement(LeadInteractionChannel::cases())->value,
            'direction'    => $this->faker->randomElement(LeadInteractionDirection::cases())->value,
            'subject'      => $this->faker->optional()->sentence(4),
            'body'         => $this->faker->paragraph(),
            'is_important' => $this->faker->boolean(15),
            'occurred_at'  => $this->faker->dateTimeBetween('-14 days', 'now'),
        ];
    }
}
