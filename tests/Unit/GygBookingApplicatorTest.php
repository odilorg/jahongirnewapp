<?php

namespace Tests\Unit;

use App\Models\GygInboundEmail;
use App\Services\GygBookingApplicator;
use App\Services\GygPickupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GygBookingApplicatorTest extends TestCase
{
    use RefreshDatabase;

    private GygBookingApplicator $applicator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicator = new GygBookingApplicator(new GygPickupResolver());
    }

    private function createParsedEmail(array $overrides = []): GygInboundEmail
    {
        // Seed a minimal tour row so matchTour() can resolve a non-null tour_id.
        // The tours table has a NOT NULL constraint on the tour_id FK in bookings.
        $tourTitle = $overrides['tour_name'] ?? 'Test Tour';
        $existingTour = DB::table('tours')->where('title', $tourTitle)->first();
        if (! $existingTour) {
            DB::table('tours')->insert([
                'title'            => $tourTitle,
                'tour_duration'    => '1 day',
                'tour_description' => 'Test tour description',
                'booking_id'       => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        return GygInboundEmail::create(array_merge([
            'email_message_id'      => '<test-' . uniqid() . '@test.com>',
            'email_from'            => 'do-not-reply@notification.getyourguide.com',
            'email_subject'         => 'Booking - S374926 - GYGTEST123',
            'email_date'            => now(),
            'body_text'             => 'test body',
            'email_type'            => 'new_booking',
            'processing_status'     => 'parsed',
            'gyg_booking_reference' => 'GYGTEST' . strtoupper(uniqid()),
            'tour_name'             => 'Test Tour',
            'option_title'          => 'Test Option',
            'guest_name'            => 'John Doe',
            'guest_email'           => 'customer-test@reply.getyourguide.com',
            'guest_phone'           => '+1234567890',
            'travel_date'           => '2026-05-01',
            'travel_time'           => '09:00:00',
            'pax'                   => 2,
            'price'                 => 100.00,
            'currency'              => 'USD',
        ], $overrides));
    }

    // ── New booking application ─────────────────────────

    public function test_creates_guest_and_booking_from_parsed_email(): void
    {
        $email = $this->createParsedEmail();
        $result = $this->applicator->applyNewBooking($email);

        $this->assertTrue($result['applied']);
        $this->assertNotNull($result['booking_id']);

        // Verify booking exists
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();
        $this->assertEquals($email->gyg_booking_reference, $booking->booking_number);
        $this->assertEquals('getyourguide', $booking->booking_source);
        $this->assertEquals('confirmed', $booking->booking_status);

        // Verify guest exists
        $guest = DB::table('guests')->where('id', $booking->guest_id)->first();
        $this->assertEquals('John', $guest->first_name);
        $this->assertEquals('Doe', $guest->last_name);

        // Verify email linked
        $email->refresh();
        $this->assertEquals('applied', $email->processing_status);
        $this->assertEquals($result['booking_id'], $email->booking_id);
    }

    public function test_idempotent_rerun_does_not_duplicate_booking(): void
    {
        $ref = 'GYGIDEMPOTENT' . strtoupper(uniqid());
        $email1 = $this->createParsedEmail(['gyg_booking_reference' => $ref]);
        $email2 = $this->createParsedEmail(['gyg_booking_reference' => $ref]);

        $result1 = $this->applicator->applyNewBooking($email1);
        $result2 = $this->applicator->applyNewBooking($email2);

        $this->assertTrue($result1['applied']);
        $this->assertTrue($result2['applied']);
        $this->assertEquals('already_exists', $result2['skipped_reason']);
        $this->assertEquals($result1['booking_id'], $result2['booking_id']);

        // Only one booking in DB
        $count = DB::table('bookings')->where('booking_number', $ref)->count();
        $this->assertEquals(1, $count);
    }

    public function test_does_not_match_guest_by_name_only(): void
    {
        // Pre-create a guest with same name but different phone/email
        DB::table('guests')->insert([
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'phone'      => '+9999999999',
            'email'      => 'other@example.com',
            'country'    => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = $this->createParsedEmail([
            'guest_phone' => '+1111111111',
            'guest_email' => 'customer-new@reply.getyourguide.com',
        ]);

        $result = $this->applicator->applyNewBooking($email);
        $this->assertTrue($result['applied']);

        // Should have created a NEW guest, not matched the existing one
        $guestCount = DB::table('guests')->where('first_name', 'John')->where('last_name', 'Doe')->count();
        $this->assertEquals(2, $guestCount);
    }

    public function test_matches_guest_by_phone(): void
    {
        $existingId = DB::table('guests')->insertGetId([
            'first_name' => 'Jane',
            'last_name'  => 'Smith',
            'email'      => 'jane.smith@test.com',
            'phone'      => '+1234567890',
            'country'    => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = $this->createParsedEmail([
            'guest_name'  => 'Jane Smith',
            'guest_phone' => '+1234567890',
        ]);

        $result = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertEquals($existingId, $booking->guest_id);
    }

    public function test_creates_synthetic_guest_when_name_absent(): void
    {
        $ref = 'GYGNONAME' . strtoupper(uniqid());
        $email = $this->createParsedEmail([
            'gyg_booking_reference' => $ref,
            'guest_name'            => null,
        ]);

        $result = $this->applicator->applyNewBooking($email);
        $this->assertTrue($result['applied']);

        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();
        $guest = DB::table('guests')->where('id', $booking->guest_id)->first();

        $this->assertEquals('GYG Guest', $guest->first_name);
        $this->assertEquals($ref, $guest->last_name);
    }

    public function test_missing_travel_time_defaults_and_is_auditable(): void
    {
        $email = $this->createParsedEmail(['travel_time' => null]);
        $result = $this->applicator->applyNewBooking($email);

        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        // Time should default to 09:00
        $this->assertStringContainsString('09:00:00', $booking->booking_start_date_time);

        // Should be noted in special_requests
        $this->assertStringContainsString('defaulted to 09:00', $booking->special_requests);
    }

    // ── Cancellation ────────────────────────────────────

    public function test_cancellation_applied_correctly(): void
    {
        $ref = 'GYGCANCEL' . strtoupper(uniqid());

        // Create existing booking
        DB::table('bookings')->insert([
            'booking_number' => $ref,
            'guest_id'       => DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'test.guest@test.com', 'phone' => 'not-provided', 'country' => 'Test', 'created_at' => now(), 'updated_at' => now()]),
            'tour_id'        => 1,
            'grand_total'    => 0,
            'amount'         => 0,
            'payment_method' => 'getyourguide',
            'payment_status' => 'paid',
            'dropoff_location' => 'TBD',
            'driver_id'      => 0,
            'guide_id'       => 0,
            'group_name'     => 'Test',
            'booking_status' => 'confirmed',
            'booking_source' => 'getyourguide',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $email = $this->createParsedEmail([
            'email_type'            => 'cancellation',
            'gyg_booking_reference' => $ref,
        ]);

        $result = $this->applicator->applyCancellation($email);

        $this->assertTrue($result['applied']);
        $booking = DB::table('bookings')->where('booking_number', $ref)->first();
        $this->assertEquals('cancelled', $booking->booking_status);
    }

    public function test_cancellation_idempotent_when_already_cancelled(): void
    {
        $ref = 'GYGCANCELIDEM' . strtoupper(uniqid());

        DB::table('bookings')->insert([
            'booking_number'   => $ref,
            'guest_id'         => DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'test.guest@test.com', 'phone' => 'not-provided', 'country' => 'Test', 'created_at' => now(), 'updated_at' => now()]),
            'tour_id'          => 1,
            'grand_total'      => 0,
            'amount'           => 0,
            'payment_method'   => 'getyourguide',
            'payment_status'   => 'paid',
            'dropoff_location' => 'TBD',
            'driver_id'        => 0,
            'guide_id'         => 0,
            'group_name'       => 'Test',
            'booking_status'   => 'cancelled',
            'booking_source'   => 'getyourguide',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $email = $this->createParsedEmail([
            'email_type'            => 'cancellation',
            'gyg_booking_reference' => $ref,
        ]);

        $result = $this->applicator->applyCancellation($email);

        $this->assertTrue($result['applied']);
        $this->assertEquals('already_cancelled', $result['skipped_reason']);
    }

    public function test_cancellation_booking_not_found_goes_to_needs_review(): void
    {
        $email = $this->createParsedEmail([
            'email_type'            => 'cancellation',
            'gyg_booking_reference' => 'GYGNONEXISTENT',
        ]);

        $result = $this->applicator->applyCancellation($email);

        $this->assertFalse($result['applied']);
        $email->refresh();
        $this->assertEquals('needs_review', $email->processing_status);
    }

    // ── Amendment ───────────────────────────────────────

    public function test_amendment_always_goes_to_needs_review(): void
    {
        $ref = 'GYGAMEND' . strtoupper(uniqid());

        DB::table('bookings')->insert([
            'booking_number' => $ref,
            'guest_id'       => DB::table('guests')->insertGetId(['first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'test.guest@test.com', 'phone' => 'not-provided', 'country' => 'Test', 'created_at' => now(), 'updated_at' => now()]),
            'tour_id'        => 1,
            'grand_total'    => 0,
            'amount'         => 0,
            'payment_method' => 'getyourguide',
            'payment_status' => 'paid',
            'dropoff_location' => 'TBD',
            'driver_id'      => 0,
            'guide_id'       => 0,
            'group_name'     => 'Test',
            'booking_status' => 'confirmed',
            'booking_source' => 'getyourguide',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $email = $this->createParsedEmail([
            'email_type'            => 'amendment',
            'gyg_booking_reference' => $ref,
        ]);

        $result = $this->applicator->handleAmendment($email);

        $this->assertFalse($result['applied']);
        $this->assertEquals('amendment_needs_review', $result['skipped_reason']);

        $email->refresh();
        $this->assertEquals('needs_review', $email->processing_status);
    }

    // ── Pickup location resolution ──────────────────────

    public function test_group_booking_gets_meeting_point_as_pickup(): void
    {
        $email = $this->createParsedEmail([
            'tour_type'        => 'group',
            'tour_type_source' => 'explicit',
            'option_title'     => 'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertSame(GygPickupResolver::GROUP_MEETING_POINT, $booking->pickup_location);
    }

    public function test_private_booking_gets_null_pickup_location(): void
    {
        $email = $this->createParsedEmail([
            'tour_type'        => 'private',
            'tour_type_source' => 'defaulted',
            'option_title'     => 'Private Shahrisabz Day Trip – Driver Only',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertNull($booking->pickup_location,
            'Private tour should have null pickup_location so reminder falls back to "your hotel"');
    }

    public function test_null_tour_type_with_private_in_option_title_gets_null_pickup(): void
    {
        // Simulates emails where tour_type was empty in DB but option_title is clear
        $email = $this->createParsedEmail([
            'tour_type'        => null,
            'tour_type_source' => null,
            'option_title'     => 'Private Shahrisabz Day Trip – Driver Only',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertNull($booking->pickup_location);
    }

    public function test_null_tour_type_with_group_in_option_title_gets_meeting_point(): void
    {
        $email = $this->createParsedEmail([
            'tour_type'        => null,
            'tour_type_source' => null,
            'option_title'     => 'Group Tour with Guide – Shahrisabz Day Trip',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertSame(GygPickupResolver::GROUP_MEETING_POINT, $booking->pickup_location);
    }

    public function test_completely_unknown_type_gets_null_pickup_conservative_fallback(): void
    {
        // No tour_type, no keywords in option_title — should be treated as private (safe default)
        $email = $this->createParsedEmail([
            'tour_type'        => null,
            'tour_type_source' => null,
            'option_title'     => 'Samarkand Day Trip',
            'tour_name'        => 'Samarkand Day Trip',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        // Conservative: null is safer than wrong meeting point
        $this->assertNull($booking->pickup_location);
    }

    public function test_defaulted_private_option_title_group_keyword_wins(): void
    {
        // tour_type=private(defaulted) but option_title contains "Group" — option_title wins
        $email = $this->createParsedEmail([
            'tour_type'        => 'private',
            'tour_type_source' => 'defaulted',
            'option_title'     => 'Group Yurt & Camel Tour',
        ]);

        $result  = $this->applicator->applyNewBooking($email);
        $booking = DB::table('bookings')->where('id', $result['booking_id'])->first();

        $this->assertSame(GygPickupResolver::GROUP_MEETING_POINT, $booking->pickup_location);
    }
}
