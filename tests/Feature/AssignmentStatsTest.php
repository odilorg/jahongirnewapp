<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Guide;
use App\Services\DriverService;
use App\Services\GuideService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for DriverService::getAssignmentStats() and GuideService::getAssignmentStats().
 *
 * Covers:
 *  - trips_today counts are correct
 *  - cancelled bookings are excluded from trips_today
 *  - bookings on other dates are excluded from trips_today
 *  - last_assigned_at reflects the most recent booking update
 *  - drivers/guides with no bookings return null for last_assigned_at and 0 trips
 *  - empty collection returns empty array
 */
class AssignmentStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->delete();
        DB::table('drivers')->delete();
        DB::table('guides')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function driverService(): DriverService
    {
        return new DriverService();
    }

    private function guideService(): GuideService
    {
        return new GuideService();
    }

    private function createDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'first_name' => 'Ali',
            'last_name'  => 'Valiyev',
            'phone01'    => '+998901111111',
            'email'      => 'ali@example.com',
            'fuel_type'  => 'Petrol',
            'is_active'  => true,
        ], $overrides));
    }

    private function createGuide(array $overrides = []): Guide
    {
        return Guide::create(array_merge([
            'first_name'  => 'Bobur',
            'last_name'   => 'Karimov',
            'phone01'     => '+998902222222',
            'email'       => 'bobur@example.com',
            'lang_spoken' => ['EN'],
            'is_active'   => true,
        ], $overrides));
    }

    private function insertBooking(array $overrides = []): int
    {
        $id = DB::table('bookings')->insertGetId(array_merge([
            'driver_id'               => null,
            'guide_id'                => null,
            'tour_id'                 => 1,
            'guest_id'                => 1,
            'grand_total'             => 0,
            'amount'                  => 0,
            'payment_method'          => 'cash',
            'payment_status'          => 'unpaid',
            'group_name'              => 'Test',
            'pickup_location'         => 'TBD',
            'dropoff_location'        => 'TBD',
            'booking_status'          => 'confirmed',
            'booking_source'          => 'test',
            'booking_start_date_time' => Carbon::today()->toDateString(),
            'created_at'              => now(),
            'updated_at'              => now(),
        ], $overrides));

        return $id;
    }

    // ── DriverService::getAssignmentStats ─────────────────────────────────────

    /** @test */
    public function empty_driver_collection_returns_empty_array(): void
    {
        $stats = $this->driverService()->getAssignmentStats(Driver::where('id', 0)->get());

        $this->assertSame([], $stats);
    }

    /** @test */
    public function driver_with_no_bookings_is_absent_from_stats(): void
    {
        $driver = $this->createDriver();
        $stats  = $this->driverService()->getAssignmentStats(Driver::where('id', $driver->id)->get());

        // No bookings → not in results (no row in GROUP BY)
        $this->assertArrayNotHasKey($driver->id, $stats);
    }

    /** @test */
    public function trips_today_counts_non_cancelled_bookings_for_today(): void
    {
        $driver = $this->createDriver();

        $this->insertBooking(['driver_id' => $driver->id, 'booking_status' => 'confirmed']);
        $this->insertBooking(['driver_id' => $driver->id, 'booking_status' => 'pending']);

        $stats = $this->driverService()->getAssignmentStats(Driver::where('id', $driver->id)->get());

        $this->assertSame(2, $stats[$driver->id]['trips_today']);
    }

    /** @test */
    public function trips_today_excludes_cancelled_bookings(): void
    {
        $driver = $this->createDriver();

        $this->insertBooking(['driver_id' => $driver->id, 'booking_status' => 'confirmed']);
        $this->insertBooking(['driver_id' => $driver->id, 'booking_status' => 'cancelled']);

        $stats = $this->driverService()->getAssignmentStats(Driver::where('id', $driver->id)->get());

        $this->assertSame(1, $stats[$driver->id]['trips_today']);
    }

    /** @test */
    public function trips_today_excludes_bookings_on_other_dates(): void
    {
        $driver = $this->createDriver();

        $this->insertBooking(['driver_id' => $driver->id, 'booking_start_date_time' => Carbon::yesterday()->toDateString()]);
        $this->insertBooking(['driver_id' => $driver->id, 'booking_start_date_time' => Carbon::tomorrow()->toDateString()]);
        $this->insertBooking(['driver_id' => $driver->id, 'booking_start_date_time' => Carbon::today()->toDateString()]);

        $stats = $this->driverService()->getAssignmentStats(Driver::where('id', $driver->id)->get());

        $this->assertSame(1, $stats[$driver->id]['trips_today']);
    }

    /** @test */
    public function last_assigned_at_is_the_most_recent_booking_updated_at(): void
    {
        $driver = $this->createDriver();

        $old = Carbon::now()->subHours(3);
        $new = Carbon::now()->subHour();

        DB::table('bookings')->insert([
            'driver_id'               => $driver->id,
            'guide_id'                => null,
            'tour_id'                 => 1,
            'guest_id'                => 1,
            'grand_total'             => 0,
            'amount'                  => 0,
            'payment_method'          => 'cash',
            'payment_status'          => 'unpaid',
            'group_name'              => 'Old',
            'pickup_location'         => 'TBD',
            'dropoff_location'        => 'TBD',
            'booking_status'          => 'confirmed',
            'booking_source'          => 'test',
            'booking_start_date_time' => Carbon::today()->toDateString(),
            'created_at'              => $old,
            'updated_at'              => $old,
        ]);

        DB::table('bookings')->insert([
            'driver_id'               => $driver->id,
            'guide_id'                => null,
            'tour_id'                 => 1,
            'guest_id'                => 1,
            'grand_total'             => 0,
            'amount'                  => 0,
            'payment_method'          => 'cash',
            'payment_status'          => 'unpaid',
            'group_name'              => 'New',
            'pickup_location'         => 'TBD',
            'dropoff_location'        => 'TBD',
            'booking_status'          => 'confirmed',
            'booking_source'          => 'test',
            'booking_start_date_time' => Carbon::today()->toDateString(),
            'created_at'              => $new,
            'updated_at'              => $new,
        ]);

        $stats = $this->driverService()->getAssignmentStats(Driver::where('id', $driver->id)->get());

        $this->assertNotNull($stats[$driver->id]['last_assigned_at']);
        // MAX(updated_at) should be within a few seconds of $new
        $parsedLast = Carbon::parse($stats[$driver->id]['last_assigned_at']);
        $this->assertTrue($parsedLast->diffInSeconds($new) < 2, 'last_assigned_at should match the newest booking updated_at');
    }

    /** @test */
    public function stats_are_isolated_per_driver_no_cross_contamination(): void
    {
        $d1 = $this->createDriver(['phone01' => '+998901111111', 'email' => 'd1@ex.com']);
        $d2 = $this->createDriver(['phone01' => '+998902222222', 'email' => 'd2@ex.com']);

        $this->insertBooking(['driver_id' => $d1->id]);
        $this->insertBooking(['driver_id' => $d1->id]);

        $drivers = Driver::whereIn('id', [$d1->id, $d2->id])->get();
        $stats   = $this->driverService()->getAssignmentStats($drivers);

        $this->assertSame(2, $stats[$d1->id]['trips_today']);
        // d2 has no bookings — absent from stats
        $this->assertArrayNotHasKey($d2->id, $stats);
    }

    // ── GuideService::getAssignmentStats ──────────────────────────────────────

    /** @test */
    public function guide_trips_today_counts_non_cancelled_bookings(): void
    {
        $guide = $this->createGuide();

        $this->insertBooking(['guide_id' => $guide->id, 'booking_status' => 'confirmed']);
        $this->insertBooking(['guide_id' => $guide->id, 'booking_status' => 'pending']);
        $this->insertBooking(['guide_id' => $guide->id, 'booking_status' => 'cancelled']);

        $stats = $this->guideService()->getAssignmentStats(Guide::where('id', $guide->id)->get());

        $this->assertSame(2, $stats[$guide->id]['trips_today']);
    }

    /** @test */
    public function guide_with_no_bookings_is_absent_from_stats(): void
    {
        $guide = $this->createGuide();
        $stats = $this->guideService()->getAssignmentStats(Guide::where('id', $guide->id)->get());

        $this->assertArrayNotHasKey($guide->id, $stats);
    }
}
