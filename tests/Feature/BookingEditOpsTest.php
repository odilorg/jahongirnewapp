<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Guest;
use App\Services\BookingOpsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for the six booking edit actions added to BookingOpsService.
 *
 * Each test creates a minimal guest + booking row directly via the models
 * (no factories), exercises the service, and asserts both the DB state change
 * and the audit-log entry.
 *
 * Scenarios:
 *  (A) editGuestName  — updates first_name / last_name, writes audit log
 *  (B) editGuestPhone — updates phone, writes audit log
 *  (C) editGuestEmail — updates email, writes audit log
 *  (D) editDate       — updates booking_start_date_time, writes audit log
 *  (E) editPax        — updates guest + booking number_of_people, writes audit log
 *  (F) editNotes      — updates special_requests, writes audit log
 *  (G) cancelled booking — all edit actions throw RuntimeException
 *  (H) editGuestName with no linked guest — throws RuntimeException
 */
class BookingEditOpsTest extends TestCase
{
    use RefreshDatabase;

    private BookingOpsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingOpsService();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a minimal guest + booking. Returns the Booking with guest loaded.
     */
    private function makeBooking(string $status = 'pending'): Booking
    {
        $guest = Guest::create([
            'first_name'       => 'John',
            'last_name'        => 'Doe',
            'email'            => 'john@example.com',
            'phone'            => '+998901234567',
            'country'          => 'US',
            'number_of_people' => 2,
        ]);

        DB::table('bookings')->insert([
            'guest_id'               => $guest->id,
            'booking_status'         => $status,
            'booking_number'         => 'TEST-001',
            'grand_total'            => 200,
            'amount'                 => 0,
            'payment_method'         => 'cash',
            'payment_status'         => 'unpaid',
            'group_name'             => 'Test Group',
            'booking_start_date_time'=> now()->addDays(10)->format('Y-m-d'),
            'dropoff_location'       => 'Registan',
            'booking_source'         => 'manual',
            // FK-less NOT NULL columns — set to 0 (no FK constraint on these)
            'driver_id'              => 0,
            'guide_id'               => 0,
            'tour_id'                => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        return Booking::with('guest')->latest('id')->first();
    }

    /** Assert one audit-log row was written for this booking with the given action. */
    private function assertAuditLog(Booking $booking, string $action): void
    {
        $this->assertDatabaseHas('booking_ops_logs', [
            'booking_id' => $booking->id,
            'action'     => $action,
            'actor'      => 'test_actor',
        ]);
    }

    // ── (A) editGuestName ─────────────────────────────────────────────────────

    /** @test */
    public function edit_guest_name_updates_first_and_last_name(): void
    {
        $booking = $this->makeBooking('pending');

        $this->service->editGuestName($booking, 'Jane', 'Smith', 'test_actor');

        $guest = $booking->guest->fresh();
        $this->assertSame('Jane',  $guest->first_name);
        $this->assertSame('Smith', $guest->last_name);
        $this->assertAuditLog($booking, 'edit_guest_name');
    }

    /** @test */
    public function edit_guest_name_works_for_confirmed_booking(): void
    {
        $booking = $this->makeBooking('confirmed');

        $this->service->editGuestName($booking, 'Alice', 'Wonder', 'test_actor');

        $this->assertSame('Alice', $booking->guest->fresh()->first_name);
        $this->assertAuditLog($booking, 'edit_guest_name');
    }

    // ── (B) editGuestPhone ────────────────────────────────────────────────────

    /** @test */
    public function edit_guest_phone_updates_phone_number(): void
    {
        $booking = $this->makeBooking('pending');

        $this->service->editGuestPhone($booking, '+1800555000', 'test_actor');

        $this->assertSame('+1800555000', $booking->guest->fresh()->phone);
        $this->assertAuditLog($booking, 'edit_guest_phone');
    }

    // ── (C) editGuestEmail ────────────────────────────────────────────────────

    /** @test */
    public function edit_guest_email_updates_email_address(): void
    {
        $booking = $this->makeBooking('pending');

        $this->service->editGuestEmail($booking, 'new@example.com', 'test_actor');

        $this->assertSame('new@example.com', $booking->guest->fresh()->email);
        $this->assertAuditLog($booking, 'edit_guest_email');
    }

    // ── (D) editDate ──────────────────────────────────────────────────────────

    /** @test */
    public function edit_date_updates_booking_start_datetime(): void
    {
        $booking  = $this->makeBooking('pending');
        $newDate  = now()->addDays(30)->format('Y-m-d');

        $this->service->editDate($booking, $newDate . ' 00:00:00', 'test_actor');

        $this->assertStringStartsWith($newDate, $booking->fresh()->booking_start_date_time);
        $this->assertAuditLog($booking, 'edit_date');
    }

    /** @test */
    public function edit_date_audit_log_records_old_and_new_values(): void
    {
        $booking = $this->makeBooking('pending');
        $oldDate = $booking->booking_start_date_time;
        $newDate = now()->addDays(45)->format('Y-m-d') . ' 00:00:00';

        $this->service->editDate($booking, $newDate, 'test_actor');

        $log = DB::table('booking_ops_logs')
            ->where('booking_id', $booking->id)
            ->where('action', 'edit_date')
            ->first();

        $this->assertNotNull($log);
        $changes = json_decode($log->changes, true);
        $this->assertSame((string) $oldDate, (string) $changes['booking_start_date_time']['old']);
        $this->assertSame($newDate,           $changes['booking_start_date_time']['new']);
    }

    // ── (E) editPax ───────────────────────────────────────────────────────────

    /** @test */
    public function edit_pax_updates_guest_and_booking_number_of_people(): void
    {
        $booking = $this->makeBooking('pending');

        $this->service->editPax($booking, 5, 'test_actor');

        // Pax count lives on the Guest record; bookings table has no number_of_people column.
        $this->assertSame(5, $booking->guest->fresh()->number_of_people);
        $this->assertAuditLog($booking, 'edit_pax');
    }

    // ── (F) editNotes ─────────────────────────────────────────────────────────

    /** @test */
    public function edit_notes_updates_special_requests(): void
    {
        $booking = $this->makeBooking('pending');

        $this->service->editNotes($booking, 'Vegetarian meals please', 'test_actor');

        $this->assertSame('Vegetarian meals please', $booking->fresh()->special_requests);
        $this->assertAuditLog($booking, 'edit_notes');
    }

    /** @test */
    public function edit_notes_can_clear_the_field(): void
    {
        $booking = $this->makeBooking('pending');
        // Seed a value first
        DB::table('bookings')->where('id', $booking->id)->update(['special_requests' => 'Old note']);

        $this->service->editNotes($booking->fresh(), '', 'test_actor');

        $this->assertSame('', $booking->fresh()->special_requests);
    }

    // ── (G) Cancelled booking — all edits blocked ────────────────────────────

    /** @test */
    public function edit_actions_throw_for_cancelled_booking(): void
    {
        $booking = $this->makeBooking('cancelled');

        $actions = [
            fn () => $this->service->editGuestName($booking, 'X', 'Y', 'actor'),
            fn () => $this->service->editGuestPhone($booking, '+1', 'actor'),
            fn () => $this->service->editGuestEmail($booking, 'x@y.com', 'actor'),
            fn () => $this->service->editDate($booking, '2030-01-01 00:00:00', 'actor'),
            fn () => $this->service->editPax($booking, 3, 'actor'),
            fn () => $this->service->editNotes($booking, 'note', 'actor'),
        ];

        foreach ($actions as $action) {
            $threw = false;
            try {
                $action();
            } catch (\RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'Expected RuntimeException for cancelled booking');
        }
    }

    // ── (H) No linked guest ───────────────────────────────────────────────────

    /** @test */
    public function edit_guest_name_throws_when_booking_has_no_guest(): void
    {
        $booking = $this->makeBooking('pending');

        // Temporarily disable FK checks to create an orphaned booking row.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->where('id', $booking->id)->update(['guest_id' => 99999]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $booking = Booking::find($booking->id); // fresh load — guest relation will return null

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no linked guest/i');

        $this->service->editGuestName($booking, 'X', 'Y', 'actor');
    }
}
