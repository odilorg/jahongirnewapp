<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generic platform-wide factory for Guide.
 *
 * Created during Phase 1 Foundation Verification (Commit 1.3).
 * Same pattern as DriverFactory.
 *
 * Required NOT NULL columns (per 2024_08_20_170140_create_guides_table):
 *   - first_name, last_name, email, phone01, guide_image
 *
 * `lang_spoken` and `is_active` were added in 2026_04_07 migrations;
 * cast to array (JSON) on the model.
 *
 * `full_name` is a DB virtual column — do NOT populate.
 */
class GuideFactory extends Factory
{
    protected $model = Guide::class;

    public function definition(): array
    {
        return [
            'first_name'  => $this->faker->firstName(),
            'last_name'   => $this->faker->lastName(),
            'email'       => $this->faker->unique()->safeEmail(),
            'phone01'     => $this->faker->e164PhoneNumber(),
            'phone02'     => null,
            'lang_spoken' => ['en'],
            'guide_image' => 'guides/placeholder.jpg',
            'is_active'   => true,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function english(): static
    {
        return $this->state(['lang_spoken' => ['en']]);
    }

    public function multilingual(): static
    {
        return $this->state(['lang_spoken' => ['en', 'ru', 'fr']]);
    }

    public function withTelegram(?string $chatId = null): static
    {
        return $this->state([
            'telegram_chat_id' => $chatId ?? (string) $this->faker->numberBetween(100000000, 999999999),
        ]);
    }
}
