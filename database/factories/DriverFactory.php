<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generic platform-wide factory for Driver.
 *
 * Created during Phase 1 Foundation Verification (Commit 1.3) when
 * the Departure governance tests surfaced that DriverFactory had
 * never shipped despite Driver using HasFactory. Same pattern as
 * Commits 1.1 (TourProductFactory) and 1.2 (BookingInquiryFactory).
 *
 * Required NOT NULL columns (per 2024_08_14_161254_create_drivers_table):
 *   - first_name, last_name, email, phone01, fuel_type
 *
 * `full_name` is a DB virtual column (concat) — do NOT populate it
 * in factories. The model's getFullNameAttribute() also overrides
 * the virtual at PHP read time.
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'first_name'   => $this->faker->firstName(),
            'last_name'    => $this->faker->lastName(),
            'email'        => $this->faker->unique()->safeEmail(),
            'phone01'      => $this->faker->e164PhoneNumber(),
            'phone02'      => null,
            'fuel_type'    => $this->faker->randomElement(['petrol', 'diesel', 'gas']),
            'driver_image' => null,
            'is_active'    => true,
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

    public function withTelegram(?string $chatId = null): static
    {
        return $this->state([
            'telegram_chat_id' => $chatId ?? (string) $this->faker->numberBetween(100000000, 999999999),
        ]);
    }
}
