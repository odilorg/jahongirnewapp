<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\Accommodation;
use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\InquiryStay;
use App\Services\Calendar\TourCalendarBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionClass;
use Tests\TestCase;

/**
 * Dispatch-chip semantics on the Tour Calendar.
 *
 * Decision (2026-04-27): chips read the authoritative `*_dispatched_at`
 * columns directly. The previous implementation grepped `internal_notes`
 * for a hardcoded substring ("Calendar dispatch TG → driver"), which
 * silently broke when DriverDispatchNotifier started writing
 * "TG dispatch → driver …" instead. Result: the slide-over showed
 * "✅ Dispatched …" while the tile said "driver not dispatched"
 * forever (incident INQ-2026-000066).
 *
 * Reading the column closes the entire string-format-drift class of bug.
 */
class DispatchChipLogicTest extends TestCase
{
    use DatabaseTransactions;

    private function callComputeReadiness(BookingInquiry $inquiry): array
    {
        $builder = app(TourCalendarBuilder::class);
        $reflect = new ReflectionClass($builder);
        $method  = $reflect->getMethod('computeReadiness');
        $method->setAccessible(true);
        return $method->invoke($builder, $inquiry);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-CHIP-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Chip Tester',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => Carbon::now('Asia/Tashkent')->addDays(2)->toDateString(),
            'submitted_at'       => now(),
        ], $overrides));
    }

    private function makeDriver(): Driver
    {
        $uniq = uniqid();
        return Driver::create([
            'first_name' => 'Test',
            'last_name'  => 'Driver',
            'email'      => "driver-{$uniq}@example.test",
            'phone01'    => '+99890' . random_int(1000000, 9999999),
            'fuel_type'  => 'gas',
            'is_active'  => true,
        ]);
    }

    private function makeGuide(): Guide
    {
        $uniq = uniqid();
        return Guide::create([
            'first_name' => 'Test',
            'last_name'  => 'Guide',
            'email'      => "guide-{$uniq}@example.test",
            'phone01'    => '+99890' . random_int(1000000, 9999999),
            'is_active'  => true,
        ]);
    }

    // ─── Driver chip ──────────────────────────────────────────────────────

    public function test_driver_chip_reads_dispatched_at_column_not_internal_notes(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
            // Internal note format from the *current* DriverDispatchNotifier:
            // does NOT contain the legacy "Calendar dispatch TG → driver"
            // substring. Old code would have flagged this as undispatched.
            'internal_notes'       => '[2026-04-27 20:09] TG dispatch → driver Foo Bar ok (msg_id=1)',
        ]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('dispatched', $readiness['chips']['driver']);
        $this->assertNotContains('driver not dispatched', $readiness['reasons']);
    }

    public function test_driver_chip_assigned_when_dispatched_at_null(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => null,
        ]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('assigned', $readiness['chips']['driver']);
        $this->assertContains('driver not dispatched', $readiness['reasons']);
    }

    public function test_driver_chip_missing_when_no_driver_assigned(): void
    {
        $inquiry = $this->makeInquiry(['driver_id' => null]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('missing', $readiness['chips']['driver']);
        $this->assertContains('no driver', $readiness['reasons']);
    }

    // ─── Guide chip ───────────────────────────────────────────────────────

    public function test_guide_chip_dispatched_when_column_set(): void
    {
        $driver  = $this->makeDriver();
        $guide   = $this->makeGuide();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
            'guide_id'             => $guide->id,
            'guide_dispatched_at'  => now(),
        ]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('dispatched', $readiness['chips']['guide']);
        $this->assertNotContains('guide not dispatched', $readiness['reasons']);
    }

    public function test_guide_chip_flags_assigned_but_not_dispatched(): void
    {
        $driver  = $this->makeDriver();
        $guide   = $this->makeGuide();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
            'guide_id'             => $guide->id,
            'guide_dispatched_at'  => null,
        ]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('assigned', $readiness['chips']['guide']);
        $this->assertContains('guide not dispatched', $readiness['reasons']);
    }

    public function test_no_guide_chip_when_no_guide_assigned(): void
    {
        // Most tours don't have a guide — adding "no guide" to reasons
        // for guideless tours would be noise. Chip key is absent.
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
            'guide_id'             => null,
        ]);

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertArrayNotHasKey('guide', $readiness['chips']);
        $this->assertNotContains('guide not dispatched', $readiness['reasons']);
        $this->assertNotContains('no guide', $readiness['reasons']);
    }

    // ─── Accommodation chip ───────────────────────────────────────────────

    public function test_accommodation_chip_dispatched_when_all_stays_have_dispatched_at(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
        ]);
        $acc = Accommodation::create([
            'name'          => 'Hotel Test',
            'type'          => 'hotel',
            'phone_primary' => '+998900000000',
            'is_active'     => true,
        ]);
        InquiryStay::create([
            'booking_inquiry_id' => $inquiry->id,
            'accommodation_id'   => $acc->id,
            'stay_date'          => $inquiry->travel_date,
            'nights'             => 1,
            'dispatched_at'      => now(),
        ]);
        $inquiry->load('stays.accommodation');

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('dispatched', $readiness['chips']['accommodation']);
        $this->assertNotContains('accommodation not dispatched', $readiness['reasons']);
    }

    public function test_accommodation_chip_assigned_when_dispatched_at_null(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
        ]);
        $acc = Accommodation::create([
            'name'          => 'Hotel Test',
            'type'          => 'hotel',
            'phone_primary' => '+998900000000',
            'is_active'     => true,
        ]);
        InquiryStay::create([
            'booking_inquiry_id' => $inquiry->id,
            'accommodation_id'   => $acc->id,
            'stay_date'          => $inquiry->travel_date,
            'nights'             => 1,
            'dispatched_at'      => null,
        ]);
        $inquiry->load('stays.accommodation');

        $readiness = $this->callComputeReadiness($inquiry);

        $this->assertEquals('assigned', $readiness['chips']['accommodation']);
        $this->assertContains('accommodation not dispatched', $readiness['reasons']);
    }

    public function test_accommodation_chip_none_when_no_stays(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'            => $driver->id,
            'driver_dispatched_at' => now(),
        ]);
        $inquiry->load('stays.accommodation');

        $readiness = $this->callComputeReadiness($inquiry);

        // Day tours don't have stays — chip is "none", no warning.
        $this->assertEquals('none', $readiness['chips']['accommodation']);
        $this->assertNotContains('accommodation not assigned', $readiness['reasons']);
        $this->assertNotContains('accommodation not dispatched', $readiness['reasons']);
    }
}
