<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Departure;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepartureFactory extends Factory
{
    protected $model = Departure::class;

    public function definition(): array
    {
        $departureDate = $this->faker->dateTimeBetween('+1 week', '+3 months');
        $departureCarbon = \Carbon\Carbon::instance($departureDate)->startOfDay();

        return [
            'reference'       => $this->makeUniqueReference(),
            'tour_product_id' => TourProduct::factory(),
            'tour_type'       => Departure::TYPE_GROUP,
            'departure_date'  => $departureCarbon->toDateString(),
            'pickup_time'     => '08:00:00',
            'pickup_point'    => 'Gur Emir Mausoleum',
            'capacity_seats'  => 12,
            'minimum_pax'     => 4,
            'cutoff_at'       => $departureCarbon->copy()->subHours(48),
            'guarantee_at'    => $departureCarbon->copy()->subHours(72),
            'status'          => Departure::STATUS_DRAFT,
            'price_per_person_usd_snapshot' => 143.00,
            'currency'        => 'USD',
        ];
    }

    public function group(): static
    {
        return $this->state(['tour_type' => Departure::TYPE_GROUP]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attrs) => [
            'tour_type'      => Departure::TYPE_PRIVATE,
            'minimum_pax'    => $attrs['capacity_seats'] ?? 1,
        ]);
    }

    public function open(): static
    {
        return $this->state([
            'status'    => Departure::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }

    public function guaranteed(): static
    {
        return $this->state([
            'status'         => Departure::STATUS_GUARANTEED,
            'opened_at'      => now()->subDays(2),
            'guaranteed_at'  => now(),
        ]);
    }

    public function withDirection(?TourProductDirection $direction = null): static
    {
        return $this->state(function (array $attrs) use ($direction) {
            return [
                'tour_product_direction_id' => $direction
                    ? $direction->id
                    : TourProductDirection::factory()
                        ->for(TourProduct::find($attrs['tour_product_id']) ?? TourProduct::factory())
                        ->create()->id,
            ];
        });
    }

    /**
     * Each factory call must produce a unique reference even when the
     * model's static generateReference() would collide (parallel test runs,
     * sequenced creates within one second).
     */
    private function makeUniqueReference(): string
    {
        static $counter = 0;
        $counter++;
        return sprintf(
            'DEP-%d-%06d',
            now()->year,
            (int) ((microtime(true) * 1000) % 1000000) + $counter
        );
    }
}
