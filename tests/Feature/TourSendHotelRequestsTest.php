<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-05-09 incident: GYG group guest
 * Matthew Sandoz (inquiry 103) received a hotel-pickup request email
 * because the cron treated "Gur Emir Mausoleum" — the canonical group
 * meeting point — as a missing-pickup placeholder, and had no
 * tour_type filter at all. Group bookings should never be asked for
 * a hotel; only private bookings without a real pickup should.
 *
 * These tests use --dry-run so no real emails are sent. We assert
 * that the command's "Sent: N" line counts only the rows that match
 * the corrected selection rule.
 */
class TourSendHotelRequestsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'customer_name' => 'Test Guest',
            'customer_email' => 'guest@example.com',
            'customer_phone' => '998901234567',
            'tour_name_snapshot' => 'Bukhara City Tour',
            'people_adults' => 2,
            'people_children' => 0,
            'travel_date' => now()->addDays(10)->toDateString(),
            'submitted_at' => now()->subHours(6),
            'created_at' => now()->subHours(6),
        ], $overrides));
    }

    private function runCommand(): string
    {
        $exit = $this->artisan('tour:send-hotel-requests --dry-run')->run();
        $this->assertSame(0, $exit);

        return (string) \Illuminate\Support\Facades\Artisan::output();
    }

    public function test_private_booking_with_null_pickup_is_selected(): void
    {
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => null,
        ]);

        $output = $this->runCommand();
        $this->assertStringContainsString($inq->customer_email, $output);
    }

    public function test_private_booking_with_empty_pickup_is_selected(): void
    {
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => '',
        ]);

        $output = $this->runCommand();
        $this->assertStringContainsString($inq->customer_email, $output);
    }

    public function test_private_booking_with_samarkand_placeholder_is_selected(): void
    {
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => 'Samarkand',
        ]);

        $output = $this->runCommand();
        $this->assertStringContainsString($inq->customer_email, $output);
    }

    public function test_group_booking_with_gur_emir_pickup_is_not_selected(): void
    {
        // The exact false-positive that emailed Matthew Sandoz on 2026-05-09.
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_GROUP,
            'pickup_point' => 'Gur Emir Mausoleum',
            'customer_email' => 'matthew-test@example.com',
        ]);

        $output = $this->runCommand();
        $this->assertStringNotContainsString('matthew-test@example.com', $output);
    }

    public function test_group_booking_with_null_pickup_is_not_selected(): void
    {
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_GROUP,
            'pickup_point' => null,
            'customer_email' => 'group-null@example.com',
        ]);

        $output = $this->runCommand();
        $this->assertStringNotContainsString('group-null@example.com', $output);
    }

    public function test_private_booking_with_real_hotel_pickup_is_not_selected(): void
    {
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => 'Hilton Samarkand Regency',
            'customer_email' => 'has-hotel@example.com',
        ]);

        $output = $this->runCommand();
        $this->assertStringNotContainsString('has-hotel@example.com', $output);
    }

    public function test_already_emailed_booking_is_not_selected_again(): void
    {
        // Idempotency: hotel_request_sent_at is the dedup key.
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => null,
            'customer_email' => 'already-sent@example.com',
            'hotel_request_sent_at' => now()->subDay(),
        ]);

        $output = $this->runCommand();
        $this->assertStringNotContainsString('already-sent@example.com', $output);
    }

    public function test_gur_emir_pickup_on_private_booking_is_not_selected(): void
    {
        // Defense-in-depth: even if a private booking somehow has Gur Emir
        // pickup (operator-set), it's a real pickup point now and should
        // not trigger a hotel ask.
        $inq = $this->makeInquiry([
            'tour_type' => BookingInquiry::TOUR_TYPE_PRIVATE,
            'pickup_point' => 'Gur Emir Mausoleum',
            'customer_email' => 'private-gur-emir@example.com',
        ]);

        $output = $this->runCommand();
        $this->assertStringNotContainsString('private-gur-emir@example.com', $output);
    }
}
