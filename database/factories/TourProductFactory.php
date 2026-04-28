<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TourProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generic platform-wide factory for TourProduct.
 *
 * Not yurt-specific. Default state is a generic active group tour with
 * realistic defaults. State methods cover yurt-camp, private-only, and
 * inactive variants for downstream tests.
 *
 * Created during Phase 1 Foundation Verification (Commit 1.1) when the
 * Departure tests surfaced that TourProductFactory had never shipped
 * despite TourProduct using HasFactory. See PHASE_0 §12.7 known failure
 * mode "Missing upstream model factories in shared domain."
 */
class TourProductFactory extends Factory
{
    protected $model = TourProduct::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->words(3, true) . ' Tour';

        return [
            'slug'              => Str::slug($title) . '-' . $this->faker->unique()->numerify('####'),
            'title'             => ucfirst($title),
            'region'            => $this->faker->randomElement(array_keys(TourProduct::REGIONS)),
            'tour_type'         => TourProduct::TYPE_GROUP,
            'duration_days'     => $this->faker->numberBetween(1, 4),
            'duration_nights'   => $this->faker->numberBetween(0, 3),
            'starting_from_usd' => $this->faker->randomFloat(2, 30, 300),
            'currency'          => 'USD',
            'description'       => $this->faker->paragraph(),
            'highlights'        => [
                $this->faker->sentence(),
                $this->faker->sentence(),
            ],
            'includes'          => 'Transport, guide, lunch.',
            'excludes'          => 'Personal expenses, tips.',
            'is_active'         => true,
            'pdf_enabled'       => false,
            'sort_order'        => 0,
            'source_type'       => 'manual',
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

    public function private(): static
    {
        return $this->state(['tour_type' => TourProduct::TYPE_PRIVATE]);
    }

    public function group(): static
    {
        return $this->state(['tour_type' => TourProduct::TYPE_GROUP]);
    }

    /**
     * Yurt camp tour state — used heavily by Departure tests. Anchored
     * to the real catalogued slug so tests align with production data
     * shape.
     */
    public function yurtCamp(): static
    {
        return $this->state([
            'slug'              => 'yurt-camp-tour-' . $this->faker->unique()->numerify('####'),
            'title'             => 'Yurt Camp Tour',
            'region'            => 'samarkand',
            'tour_type'         => TourProduct::TYPE_PRIVATE,
            'duration_days'     => 2,
            'duration_nights'   => 1,
            'starting_from_usd' => 143.00,
        ]);
    }
}
