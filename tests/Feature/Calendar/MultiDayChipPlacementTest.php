<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Services\Calendar\TourCalendarBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Calendar chip placement for multi-day tours.
 *
 * Pre-patch: a 3-day tour rendered only on its start date and lied to
 * dispatchers about which days had it running. This pins the corrected
 * behaviour — chip extends across the full date range, falls back to
 * tour_products.duration_days when no per-booking stays exist, and the
 * lookback widens enough to surface in-progress tours that started
 * before the visible window.
 */
class MultiDayChipPlacementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeProduct(int $days, int $nights, string $title = 'Test Multi-Day'): TourProduct
    {
        $product = TourProduct::create([
            'title'             => $title,
            'slug'              => 'test-multi-day-' . uniqid(),
            'region'            => 'samarkand',
            'is_active'         => true,
            'duration_days'     => $days,
            'duration_nights'   => $nights,
        ]);

        TourProductDirection::create([
            'tour_product_id' => $product->id,
            'code'            => 'default',
            'name'            => 'Default route',
            'is_active'       => true,
        ]);

        return $product;
    }

    private function makeInquiry(string $travelDate, ?int $productId = null, array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-MD-' . uniqid(),
            'source'             => 'manual',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Multi-Day Tester',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test',
            'tour_product_id'    => $productId,
            'people_adults'      => 2,
            'travel_date'        => $travelDate,
            'submitted_at'       => now(),
        ], $overrides));
    }

    /** @test */
    public function single_day_tour_renders_chip_on_one_day_only(): void
    {
        $product = $this->makeProduct(days: 1, nights: 0);
        $monday  = Carbon::parse('2026-06-01')->startOfDay();
        $this->makeInquiry($monday->toDateString(), $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($monday, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0] ?? null;
        $this->assertNotNull($chip);
        $this->assertSame(1, $chip['duration']);
        $this->assertSame(0, $chip['total_nights']);
        $this->assertSame(0, $chip['day_index']);  // start = window day 0
    }

    /** @test */
    public function multi_day_tour_chip_carries_full_duration_from_catalog(): void
    {
        $product = $this->makeProduct(days: 3, nights: 2);
        $monday  = Carbon::parse('2026-06-01')->startOfDay();
        $this->makeInquiry($monday->toDateString(), $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($monday, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0];
        $this->assertSame(3, $chip['duration'], 'duration falls back to catalog days');
        $this->assertSame(2, $chip['total_nights']);
        $this->assertSame($monday->toDateString(), $chip['start_date_iso']);
        $this->assertSame($monday->copy()->addDays(2)->toDateString(), $chip['end_date_iso']);
    }

    /** @test */
    public function tour_started_before_window_still_appears_if_running_inside_it(): void
    {
        $product = $this->makeProduct(days: 5, nights: 4);
        $window  = Carbon::parse('2026-06-08')->startOfDay();   // Monday
        // Tour started Sat 2026-06-06, runs through Wed 2026-06-10
        $this->makeInquiry('2026-06-06', $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($window, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0] ?? null;
        $this->assertNotNull($chip, 'in-progress tour started before window must still surface');
        $this->assertSame(-2, $chip['day_index'], 'day_index is negative for tours that started earlier');
        $this->assertSame(5, $chip['duration']);
    }

    /** @test */
    public function tour_fully_outside_window_is_dropped(): void
    {
        $product = $this->makeProduct(days: 2, nights: 1);
        $window  = Carbon::parse('2026-06-15')->startOfDay();
        // Tour 2026-06-01 → 2026-06-02, ended 13 days before window
        $this->makeInquiry('2026-06-01', $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($window, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $this->assertSame([], $week['rows'], 'finished tours must not pollute the week view');
    }

    /** @test */
    public function multi_day_tour_without_catalog_link_falls_back_to_one_day(): void
    {
        // Manual entry, no tour_product_id. Behaviour shouldn't pretend
        // a duration we don't know — render as a day chip and move on.
        $monday = Carbon::parse('2026-06-01')->startOfDay();
        $this->makeInquiry($monday->toDateString(), null);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($monday, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0];
        $this->assertSame(1, $chip['duration']);
        $this->assertSame(0, $chip['total_nights']);
    }
}
