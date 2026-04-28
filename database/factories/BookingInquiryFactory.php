<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingInquiry;
use App\Models\Departure;
use App\Models\TourProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generic platform-wide factory for BookingInquiry.
 *
 * Created during Phase 1 Foundation Verification (Commit 1.2) when the
 * Departure tests surfaced that BookingInquiryFactory had never shipped
 * despite BookingInquiry using HasFactory. See PHASE_0 §12.7
 * "Missing upstream model factories in shared domain."
 *
 * Design philosophy:
 *   - Minimal valid base. Just enough to satisfy NOT NULL constraints.
 *   - States carry the behavioral specificity (status transitions,
 *     departure linkage, payment markers).
 *   - NOT a yurt-camp-only factory. This is platform infrastructure
 *     that any feature touching BookingInquiry can compose.
 *
 * Required (NOT NULL, no default) columns the base definition must populate:
 *   - reference                — generated unique per call
 *   - tour_name_snapshot       — string
 *   - customer_name            — string
 *   - customer_phone           — string
 *   (other NOT NULL fields have schema defaults, but factory still
 *    provides explicit values for test clarity)
 */
class BookingInquiryFactory extends Factory
{
    protected $model = BookingInquiry::class;

    public function definition(): array
    {
        return [
            'reference'          => $this->makeUniqueReference(),
            'source'             => BookingInquiry::SOURCE_WEBSITE,
            'tour_slug'          => 'generic-tour-' . $this->faker->unique()->numerify('####'),
            'tour_name_snapshot' => 'Generic Tour',
            'customer_name'      => $this->faker->name(),
            'customer_email'     => $this->faker->safeEmail(),
            'customer_phone'     => $this->faker->e164PhoneNumber(),
            'people_adults'      => 2,
            'people_children'    => 0,
            'flexible_dates'     => false,
            'status'             => BookingInquiry::STATUS_NEW,
            'currency'           => 'USD',
            'submitted_at'       => now(),
        ];
    }

    // ─── Status states ──────────────────────────────────────

    public function newLead(): static
    {
        return $this->state(['status' => BookingInquiry::STATUS_NEW]);
    }

    public function contacted(): static
    {
        return $this->state([
            'status'       => BookingInquiry::STATUS_CONTACTED,
            'contacted_at' => now(),
        ]);
    }

    public function awaitingPayment(): static
    {
        return $this->state([
            'status'        => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'price_quoted'  => 286.00,
            'payment_link'  => 'https://secure.octo.uz/payment/example',
        ]);
    }

    public function confirmed(): static
    {
        return $this->state([
            'status'         => BookingInquiry::STATUS_CONFIRMED,
            'price_quoted'   => 286.00,
            'paid_at'        => now(),
            'payment_method' => BookingInquiry::PAYMENT_ONLINE,
            'confirmed_at'   => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status'       => BookingInquiry::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function spam(): static
    {
        return $this->state(['status' => BookingInquiry::STATUS_SPAM]);
    }

    // ─── Departure-bound states ─────────────────────────────

    /**
     * Bind this inquiry to a specific Departure with a seat hold.
     * Default 2 seats, 24h hold. Override seats via ->state(['seats_held' => N]).
     */
    public function forDeparture(Departure $departure, int $seats = 2): static
    {
        return $this->state(function (array $attrs) use ($departure, $seats) {
            return [
                'departure_id'         => $departure->id,
                'tour_product_id'      => $departure->tour_product_id,
                'tour_product_direction_id' => $departure->tour_product_direction_id,
                'tour_type'            => $departure->tour_type,
                'travel_date'          => $departure->departure_date,
                'pickup_time'          => $departure->pickup_time,
                'pickup_point'         => $departure->pickup_point,
                'seats_held'           => $seats,
                'price_quoted'         => $departure->price_per_person_usd_snapshot * $seats,
            ];
        });
    }

    public function activeSeatHold(int $hoursRemaining = 24): static
    {
        return $this->state([
            'status'                => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'seat_hold_expires_at'  => now()->addHours($hoursRemaining),
            'payment_due_at'        => now()->addHours($hoursRemaining),
        ]);
    }

    public function expiredSeatHold(): static
    {
        return $this->state([
            'status'                => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'seat_hold_expires_at'  => now()->subHours(1),
            'payment_due_at'        => now()->subHours(1),
        ]);
    }

    // ─── Reference generator ────────────────────────────────

    /**
     * Each factory call produces a unique reference even when sequenced
     * within one second (parallel test runs, fast successive creates).
     * Mirrors the pattern in DepartureFactory.
     */
    private function makeUniqueReference(): string
    {
        static $counter = 0;
        $counter++;
        return sprintf(
            'INQ-%d-%06d',
            now()->year,
            (int) ((microtime(true) * 1000) % 1000000) + $counter
        );
    }
}
