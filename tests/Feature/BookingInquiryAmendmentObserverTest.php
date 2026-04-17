<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Models\Driver;
use App\Models\Guide;
use App\Services\DriverDispatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 19.1 — BookingInquiryObserver behavior tests.
 *
 * These tests verify the actual promises made about amendment notifications:
 * who gets notified, who doesn't, under which assignment + dispatch combos.
 */
class BookingInquiryAmendmentObserverTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $dispatcherMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze tg-direct so real sends don't happen during tests.
        config(['services.tg_direct.enabled' => true]);

        $this->dispatcherMock = $this->mock(DriverDispatchNotifier::class);
        $this->dispatcherMock->shouldReceive('notifyAmendment')->byDefault()->andReturn(['ok' => true, 'msg_id' => 1]);
        $this->dispatcherMock->shouldReceive('notifySupplierRemoved')->byDefault()->andReturn(['ok' => true, 'msg_id' => 1]);
        $this->dispatcherMock->shouldReceive('dispatchDriver')->byDefault()->andReturn(['ok' => true, 'msg_id' => 1]);
        $this->dispatcherMock->shouldReceive('dispatchGuide')->byDefault()->andReturn(['ok' => true, 'msg_id' => 1]);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Test Guest',
            'customer_email'     => 't@e.st',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'travel_date'        => '2026-05-01',
            'pickup_time'        => '09:00',
            'pickup_point'       => 'Registan',
            'people_adults'      => 4,
            'people_children'    => 0,
            'submitted_at'       => now(),
            'ip_address'         => '127.0.0.1',
        ], $overrides));
    }

    private function makeDriver(): Driver
    {
        return Driver::create([
            'first_name' => 'Hasan',
            'last_name'  => 'T',
            'phone01'    => '+998901111111',
            'email'      => 'hasan@test.local',
            'is_active'  => true,
        ]);
    }

    private function makeGuide(): Guide
    {
        return Guide::create([
            'first_name' => 'Mehroj',
            'last_name'  => 'T',
            'phone01'    => '+998902222222',
            'email'      => 'mehroj@test.local',
            'is_active'  => true,
        ]);
    }

    public function test_no_suppliers_assigned_no_notifications(): void
    {
        $inquiry = $this->makeInquiry();

        $this->dispatcherMock->shouldNotReceive('notifyAmendment');
        $this->dispatcherMock->shouldNotReceive('dispatchDriver');

        $inquiry->update(['travel_date' => '2026-05-15']);

        $this->assertTrue(true);
    }

    public function test_driver_assigned_but_not_dispatched_gets_no_amendment(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'      => $driver->id,
            'internal_notes' => null,
        ]);

        // No "Calendar dispatch TG → driver" marker → wasDispatched = false
        $this->dispatcherMock->shouldNotReceive('notifyAmendment');

        $inquiry->update(['travel_date' => '2026-05-15']);
    }

    public function test_driver_dispatched_and_date_changes_gets_amendment(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'      => $driver->id,
            'internal_notes' => '[2026-04-16 20:33] Calendar dispatch TG → driver Hasan T ok (msg_id=12345)',
        ]);

        $this->dispatcherMock->shouldReceive('notifyAmendment')
            ->once()
            ->withArgs(function ($arg, $role, $changes) {
                return $role === 'driver'
                    && array_key_exists('travel_date', $changes);
            });

        $inquiry->update(['travel_date' => '2026-05-15']);
    }

    public function test_multiple_field_changes_produce_single_consolidated_message(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id'      => $driver->id,
            'internal_notes' => 'Calendar dispatch TG → driver Hasan T ok',
        ]);

        $this->dispatcherMock->shouldReceive('notifyAmendment')
            ->once() // CRITICAL: exactly one call, not 3
            ->withArgs(function ($arg, $role, $changes) {
                return count($changes) === 3; // travel_date + pickup_time + people_adults
            });

        $inquiry->update([
            'travel_date'   => '2026-05-15',
            'pickup_time'   => '10:30',
            'people_adults' => 5,
        ]);
    }

    public function test_driver_reassignment_removes_old_and_dispatches_new(): void
    {
        $oldDriver = $this->makeDriver();
        $newDriver = Driver::create([
            'first_name' => 'Muhammad', 'last_name' => 'T',
            'phone01' => '+998903333333', 'email' => 'muh@test.local', 'is_active' => true,
        ]);
        $inquiry = $this->makeInquiry([
            'driver_id' => $oldDriver->id,
            'internal_notes' => 'Calendar dispatch TG → driver Hasan ok',
        ]);

        $this->dispatcherMock->shouldReceive('notifySupplierRemoved')->once();
        $this->dispatcherMock->shouldReceive('dispatchDriver')->once();
        $this->dispatcherMock->shouldNotReceive('notifyAmendment');

        $inquiry->update(['driver_id' => $newDriver->id]);
    }

    public function test_initial_driver_assignment_does_not_auto_dispatch(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry(['driver_id' => null]);

        // null → X is initial assignment, not reassignment
        $this->dispatcherMock->shouldNotReceive('dispatchDriver');
        $this->dispatcherMock->shouldNotReceive('notifySupplierRemoved');
        $this->dispatcherMock->shouldNotReceive('notifyAmendment');

        $inquiry->update(['driver_id' => $driver->id]);
    }

    public function test_driver_unassignment_notifies_old_driver_only(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id' => $driver->id,
            'internal_notes' => 'Calendar dispatch TG → driver Hasan ok',
        ]);

        $this->dispatcherMock->shouldReceive('notifySupplierRemoved')->once();
        $this->dispatcherMock->shouldNotReceive('dispatchDriver');

        $inquiry->update(['driver_id' => null]);
    }

    public function test_cancelled_booking_does_not_trigger_amendment(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id' => $driver->id,
            'status'    => BookingInquiry::STATUS_CANCELLED,
            'internal_notes' => 'Calendar dispatch TG → driver Hasan ok',
        ]);

        $this->dispatcherMock->shouldNotReceive('notifyAmendment');
        $this->dispatcherMock->shouldNotReceive('notifySupplierRemoved');

        $inquiry->update(['travel_date' => '2026-05-15']);
    }

    public function test_non_critical_field_change_does_not_trigger(): void
    {
        $driver  = $this->makeDriver();
        $inquiry = $this->makeInquiry([
            'driver_id' => $driver->id,
            'internal_notes' => 'Calendar dispatch TG → driver Hasan ok',
        ]);

        $this->dispatcherMock->shouldNotReceive('notifyAmendment');

        $inquiry->update(['price_quoted' => 1500, 'internal_notes' => $inquiry->internal_notes . "\nmemo added"]);
    }

    public function test_both_driver_and_guide_dispatched_both_get_notified(): void
    {
        $driver  = $this->makeDriver();
        $guide   = $this->makeGuide();
        $inquiry = $this->makeInquiry([
            'driver_id' => $driver->id,
            'guide_id'  => $guide->id,
            'internal_notes' => "Calendar dispatch TG → driver Hasan ok\nCalendar dispatch TG → guide Mehroj ok",
        ]);

        $this->dispatcherMock->shouldReceive('notifyAmendment')->twice();

        $inquiry->update(['pickup_time' => '11:00']);
    }
}
