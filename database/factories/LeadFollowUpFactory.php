<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFollowUpFactory extends Factory
{
    protected $model = LeadFollowUp::class;

    public function definition(): array
    {
        return [
            'due_at' => $this->faker->dateTimeBetween('-2 days', '+7 days'),
            'type'   => $this->faker->randomElement(LeadFollowUpType::cases())->value,
            'note'   => $this->faker->sentence(),
            'status' => LeadFollowUpStatus::Open->value,
        ];
    }
}
