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

    /** @test */
    public function chip_carries_clipped_visible_window_when_truncated_left(): void
    {
        // Tour starts before window (Sat) and runs into window (Mon-Wed).
        $product = $this->makeProduct(days: 5, nights: 4);
        $window  = Carbon::parse('2026-06-08')->startOfDay();
        $this->makeInquiry('2026-06-06', $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($window, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0];
        $this->assertTrue($chip['clip_left']);
        $this->assertFalse($chip['clip_right']);
        $this->assertSame(0, $chip['visible_start_col'], 'visible start clamps to window left');
        $this->assertSame(2, $chip['visible_end_col'],   'tour ends Wed = col 2');
        $this->assertSame(3, $chip['visible_span']);
    }

    /** @test */
    public function chip_carries_clipped_visible_window_when_truncated_right(): void
    {
        // Tour starts Friday in-window and runs into next week.
        $product = $this->makeProduct(days: 4, nights: 3);
        $window  = Carbon::parse('2026-06-01')->startOfDay();   // Mon
        $this->makeInquiry('2026-06-05', $product->id);          // Fri

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($window, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chip = $week['rows'][0]['chips'][0];
        $this->assertFalse($chip['clip_left']);
        $this->assertTrue($chip['clip_right']);
        $this->assertSame(4, $chip['visible_start_col'], 'Fri = col 4 from anchor');
        $this->assertSame(6, $chip['visible_end_col'],   'right clamps to window right');
        $this->assertSame(3, $chip['visible_span']);
    }

    /** @test */
    public function overlapping_bookings_get_distinct_lanes(): void
    {
        // Two 3-day bookings of the same tour, overlapping by 1 day.
        $product = $this->makeProduct(days: 3, nights: 2);
        $monday  = Carbon::parse('2026-06-01')->startOfDay();
        $this->makeInquiry($monday->toDateString(), $product->id);             // Mon-Wed
        $this->makeInquiry($monday->copy()->addDays(2)->toDateString(), $product->id); // Wed-Fri

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($monday, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $chips = $week['rows'][0]['chips'];
        $this->assertCount(2, $chips);
        $lanes = array_column($chips, 'lane_index');
        sort($lanes);
        $this->assertSame([0, 1], $lanes, 'overlapping bookings must NOT share a lane');
        $this->assertSame(2, $week['rows'][0]['lane_count']);
    }

    /** @test */
    public function non_overlapping_bookings_reuse_the_same_lane(): void
    {
        // Two day-tour bookings on different days — no collision.
        $product = $this->makeProduct(days: 1, nights: 0);
        $monday  = Carbon::parse('2026-06-01')->startOfDay();
        $this->makeInquiry($monday->toDateString(), $product->id);
        $this->makeInquiry($monday->copy()->addDays(3)->toDateString(), $product->id);

        $week = app(TourCalendarBuilder::class)
            ->buildWeek($monday, [BookingInquiry::STATUS_CONFIRMED], startFromAnchor: true);

        $row = $week['rows'][0];
        $this->assertCount(2, $row['chips']);
        $this->assertSame(0, $row['chips'][0]['lane_index']);
        $this->assertSame(0, $row['chips'][1]['lane_index']);
        $this->assertSame(1, $row['lane_count'], 'only one lane is needed when chips do not overlap');
    }
}
