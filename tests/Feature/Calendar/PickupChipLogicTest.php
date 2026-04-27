<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\BookingInquiry;
use App\Services\Calendar\TourCalendarBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pickup-chip semantics on the Tour Calendar.
 *
 * Decision: "operationally complete" pickup means the driver knows where
 * to go — not just "the column is non-empty". Group tours treat
 * Gur Emir / Samarkand as the standard meeting point (no warning);
 * private tours still need a real hotel name (warning persists).
 *
 * Tests the private helper directly via reflection — it's the load-
 * bearing function shared by both the chip and the warnings list, so
 * pinning its behavior is the smallest possible regression net.
 */
class PickupChipLogicTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-PICKUP-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Pickup Tester',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => Carbon::now('Asia/Tashkent')->addDays(3)->toDateString(),
            'submitted_at'       => now(),
        ], $overrides));
    }

    private function isPickupOperationallyComplete(BookingInquiry $inquiry): bool
    {
        $builder = app(TourCalendarBuilder::class);
        $reflect = new ReflectionClass($builder);
        $method  = $reflect->getMethod('isPickupOperationallyComplete');
        $method->setAccessible(true);
        return (bool) $method->invoke($builder, $inquiry);
    }

    public function test_blank_pickup_is_incomplete_for_any_tour_type(): void
    {
        foreach (['group', 'private', null] as $type) {
            $inquiry = $this->makeInquiry([
                'pickup_point' => null,
                'tour_type'    => $type,
            ]);
            $this->assertFalse(
                $this->isPickupOperationallyComplete($inquiry),
                "blank pickup must be incomplete (tour_type={$type})"
            );
        }
    }

    public function test_group_tour_at_gur_emir_is_complete(): void
    {
        $inquiry = $this->makeInquiry([
            'pickup_point' => 'Gur Emir Mausoleum',
            'tour_type'    => 'group',
        ]);
        $this->assertTrue(
            $this->isPickupOperationallyComplete($inquiry),
            'Group tour with the standard meeting point must NOT show "no pickup"'
        );
    }

    public function test_group_tour_at_samarkand_default_is_complete(): void
    {
        $inquiry = $this->makeInquiry([
            'pickup_point' => 'Samarkand',
            'tour_type'    => 'group',
        ]);
        $this->assertTrue($this->isPickupOperationallyComplete($inquiry));
    }

    public function test_private_tour_at_gur_emir_is_still_incomplete(): void
    {
        $inquiry = $this->makeInquiry([
            'pickup_point' => 'Gur Emir Mausoleum',
            'tour_type'    => 'private',
        ]);
        $this->assertFalse(
            $this->isPickupOperationallyComplete($inquiry),
            'Private tours need a real hotel name — keep the warning'
        );
    }

    public function test_private_tour_at_samarkand_is_still_incomplete(): void
    {
        $inquiry = $this->makeInquiry([
            'pickup_point' => 'Samarkand',
            'tour_type'    => 'private',
        ]);
        $this->assertFalse($this->isPickupOperationallyComplete($inquiry));
    }

    public function test_real_hotel_name_is_complete_for_any_tour_type(): void
    {
        foreach (['group', 'private', null] as $type) {
            $inquiry = $this->makeInquiry([
                'pickup_point' => 'Hotel Bibikhanum',
                'tour_type'    => $type,
            ]);
            $this->assertTrue(
                $this->isPickupOperationallyComplete($inquiry),
                "Specific hotel name must be complete (tour_type={$type})"
            );
        }
    }
}
