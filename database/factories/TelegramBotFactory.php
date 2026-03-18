<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Models\TelegramBot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBot>
 */
class TelegramBotFactory extends Factory
{
    protected $model = TelegramBot::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(3, true) . ' Bot',
            'bot_username' => '@' . $this->faker->unique()->userName(),
            'description' => $this->faker->optional()->sentence(),
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Production,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => BotStatus::Active]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => BotStatus::Disabled]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => BotStatus::Revoked]);
    }

    public function forEnvironment(BotEnvironment $env): static
    {
        return $this->state(fn () => ['environment' => $env]);
    }
}
