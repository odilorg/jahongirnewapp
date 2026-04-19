<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadContactChannel;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'phone'             => $this->faker->e164PhoneNumber(),
            'email'             => $this->faker->safeEmail(),
            'whatsapp_number'   => $this->faker->e164PhoneNumber(),
            'preferred_channel' => $this->faker->randomElement(LeadContactChannel::cases())->value,
            'source'            => $this->faker->randomElement(LeadSource::cases())->value,
            'language'          => $this->faker->randomElement(['en', 'ru', 'uz']),
            'country'           => $this->faker->countryCode(),
            'status'            => LeadStatus::New->value,
            'priority'          => LeadPriority::Medium->value,
            'notes'             => $this->faker->optional()->sentence(),
        ];
    }
}
