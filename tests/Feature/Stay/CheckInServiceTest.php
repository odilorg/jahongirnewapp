<?php

namespace Tests\Feature\Stay;

use App\Models\Beds24Booking;
use App\Models\StayTransitionLog;
use App\Models\User;
use App\Services\Stay\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInServiceTest extends TestCase
{
    use RefreshDatabase;

    private CheckInService $service;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CheckInService();
        $this->actor   = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_confirmed_booking_can_be_checked_in(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'confirmed']);

        $result = $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);

        $this->assertSame('checked_in', $result->booking_status);
        $this->assertDatabaseHas('beds24_bookings', [
            'beds24_booking_id' => $booking->beds24_booking_id,
            'booking_status'    => 'checked_in',
        ]);
    }

    public function test_new_status_booking_can_be_checked_in(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'new']);

        $result = $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);

        $this->assertSame('checked_in', $result->booking_status);
    }

    // -------------------------------------------------------------------------
    // Blocked transitions
    // -------------------------------------------------------------------------

    public function test_cancelled_booking_is_blocked(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'cancelled']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches("/cancelled/");

        $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
    }

    public function test_no_show_booking_is_blocked(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'no_show']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches("/no_show/");

        $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
    }

    public function test_already_checked_in_booking_is_blocked(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'checked_in']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches("/checked_in/");

        $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
    }

    public function test_already_checked_out_booking_is_blocked(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'checked_out']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches("/checked_out/");

        $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
    }

    // -------------------------------------------------------------------------
    // Audit log
    // -------------------------------------------------------------------------

    public function test_successful_check_in_writes_audit_log(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'confirmed']);

        $this->service->checkIn($booking->beds24_booking_id, $this->actor->id, 'telegram_cashier_bot');

        $this->assertDatabaseHas('stay_transition_logs', [
            'beds24_booking_id' => $booking->beds24_booking_id,
            'actor_user_id'     => $this->actor->id,
            'action'            => 'check_in',
            'old_status'        => 'confirmed',
            'new_status'        => 'checked_in',
            'source'            => 'telegram_cashier_bot',
        ]);
    }

    public function test_blocked_check_in_writes_no_audit_log(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'cancelled']);

        try {
            $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
        } catch (\DomainException) {
            // expected
        }

        $this->assertDatabaseMissing('stay_transition_logs', [
            'beds24_booking_id' => $booking->beds24_booking_id,
        ]);
    }

    public function test_blocked_check_in_leaves_status_unchanged(): void
    {
        $booking = Beds24Booking::factory()->create(['booking_status' => 'cancelled']);

        try {
            $this->service->checkIn($booking->beds24_booking_id, $this->actor->id);
        } catch (\DomainException) {
            // expected
        }

        $this->assertDatabaseHas('beds24_bookings', [
            'beds24_booking_id' => $booking->beds24_booking_id,
            'booking_status'    => 'cancelled',
        ]);
    }
}
