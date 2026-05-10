<?php

declare(strict_types=1);

namespace Tests\Feature\BookingInquiries;

use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Services\Calendar\TourCalendarBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * End-to-end regression for inquiry 109's user-visible failure mode:
 * "calendar chip occupies only 1 day even though the tour is 2 days."
 *
 * The data-layer test (EnrichWebsiteInquiryFromTourSlugActionTest) proves
 * tour_product_id gets linked. This test proves the linkage actually
 * causes the calendar to render a multi-day chip.
 */
class CalendarChipSpansFromTourProductTest extends TestCase
{
    use DatabaseTransactions;

    public function test_two_day_product_yields_two_day_chip_span(): void
    {
        $product = TourProduct::create([
            'slug'            => 'two-day-test-tour-'.uniqid(),
            'title'           => '2-Day Test Tour',
            'duration_days'   => 2,
            'duration_nights' => 1,
            'tour_type'       => 'private',
            'is_active'       => true,
        ]);

        TourProductDirection::create([
            'tour_product_id' => $product->id,
            'code'            => 'default',
            'name'            => 'Default',
            'is_active'       => true,
            'sort_order'      => 0,
        ]);

        $travelDate = Carbon::create(2030, 6, 2);

        $inquiry = BookingInquiry::create([
            'reference'                 => 'INQ-CAL-'.uniqid(),
            'source'                    => 'website',
            'status'                    => BookingInquiry::STATUS_CONFIRMED,
            'tour_type'                 => 'private',
            'tour_product_id'           => $product->id,
            'tour_product_direction_id' => $product->directions()->first()->id,
            'tour_slug'                 => $product->slug,
            'tour_name_snapshot'        => $product->title,
            'customer_name'             => 'Span Test Guest',
            'customer_email'            => 'span@test.example',
            'customer_phone'            => '+998901234567',
            'people_adults'             => 2,
            'people_children'           => 0,
            'travel_date'               => $travelDate->toDateString(),
            'submitted_at'              => now(),
        ]);

        $builder = app(TourCalendarBuilder::class);
        $week    = $builder->buildWeek($travelDate, ['confirmed'], startFromAnchor: true);

        // Find our chip across the rows the builder produced.
        $chip = null;
        foreach ($week['rows'] ?? [] as $row) {
            foreach ($row['chips'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === $inquiry->id) {
                    $chip = $candidate;
                    break 2;
                }
            }
        }

        $this->assertNotNull($chip, 'inquiry chip not found in calendar window');
        $this->assertGreaterThanOrEqual(2, $chip['visible_span'], 'chip must span at least 2 days when product.duration_days=2');
    }

    public function test_inquiry_without_tour_product_collapses_to_one_day(): void
    {
        // Negative control: an inquiry with NO tour_product_id (the bug
        // state) collapses to a 1-day chip. This pins the failure mode.
        $travelDate = Carbon::create(2030, 7, 5);

        $inquiry = BookingInquiry::create([
            'reference'          => 'INQ-CAL-NULL-'.uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'tour_product_id'    => null,
            'tour_name_snapshot' => 'Unlinked snapshot',
            'customer_name'      => 'No Link Guest',
            'customer_email'     => 'nolink@test.example',
            'customer_phone'     => '+998901234567',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => $travelDate->toDateString(),
            'submitted_at'       => now(),
        ]);

        $builder = app(TourCalendarBuilder::class);
        $week    = $builder->buildWeek($travelDate, ['confirmed'], startFromAnchor: true);

        $chip = null;
        foreach ($week['rows'] ?? [] as $row) {
            foreach ($row['chips'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === $inquiry->id) {
                    $chip = $candidate;
                    break 2;
                }
            }
        }

        $this->assertNotNull($chip);
        $this->assertSame(1, $chip['visible_span'], 'unlinked inquiry must collapse to 1-day chip (current bug state)');
    }
}
