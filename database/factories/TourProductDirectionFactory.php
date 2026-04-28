<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TourProduct;
use App\Models\TourProductDirection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generic platform-wide factory for TourProductDirection.
 *
 * Created during Phase 1 Foundation Verification (Commit 1.1). State
 * methods cover the canonical yurt-camp routes plus generic active /
 * inactive variants.
 *
 * NOTE: `default_pickup_point` is the column added in
 * 2026_04_28_171726_add_default_pickup_to_tour_product_directions.
 * Factory must populate it (nullable but operationally expected).
 */
class TourProductDirectionFactory extends Factory
{
    protected $model = TourProductDirection::class;

    public function definition(): array
    {
        $code = $this->faker->unique()->slug(2);

        return [
            'tour_product_id'      => TourProduct::factory(),
            'code'                 => $code,
            'name'                 => ucfirst(str_replace('-', ' → ', $code)),
            'start_city'           => 'Samarkand',
            'end_city'             => 'Bukhara',
            'default_pickup_point' => 'Gur Emir Mausoleum',
            'notes'                => null,
            'is_active'            => true,
            'sort_order'           => 0,
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

    public function samarkandToBukhara(): static
    {
        return $this->state([
            'code'                 => 'sam-bukhara',
            'name'                 => 'Samarkand → Bukhara',
            'start_city'           => 'Samarkand',
            'end_city'             => 'Bukhara',
            'default_pickup_point' => 'Gur Emir Mausoleum',
        ]);
    }

    public function bukharaToSamarkand(): static
    {
        return $this->state([
            'code'                 => 'bukhara-sam',
            'name'                 => 'Bukhara → Samarkand',
            'start_city'           => 'Bukhara',
            'end_city'             => 'Samarkand',
            'default_pickup_point' => 'Lyabi-Hauz',
        ]);
    }

    public function samarkandLoop(): static
    {
        return $this->state([
            'code'                 => 'sam-sam',
            'name'                 => 'Samarkand loop',
            'start_city'           => 'Samarkand',
            'end_city'             => 'Samarkand',
            'default_pickup_point' => 'Gur Emir Mausoleum',
        ]);
    }
}
