<?php

declare(strict_types=1);

namespace Tests\Feature\Viator;

use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Models\ViatorInboundEmail;
use App\Services\Viator\ViatorEmailParser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * End-to-end: parsed Viator email row → BookingInquiry creation.
 *
 * Pins the auto-apply contract:
 *   - new + matched catalog product → status APPLIED, BookingInquiry created
 *   - new + unmatched product       → status NEEDS_REVIEW, inquiry still
 *     created (so dispatch board sees it) but error_message flags reason
 *   - duplicate external_reference  → linked to existing inquiry, not duped
 *   - amendments + cancellations    → never auto-applied (PR-V2 territory)
 */
final class ViatorApplyNewBookingsTest extends TestCase
{
    use DatabaseTransactions;

    private ViatorEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ViatorEmailParser();
    }

    private function loadFixture(string $name): array
    {
        $path = base_path('tests/Fixtures/Viator/' . $name . '.txt');
        $raw  = file_get_contents($path);
        preg_match('/^Subject:\s*(.+)$/m', $raw, $sm);
        $body = preg_split('/\r?\n\r?\n/', $raw, 2)[1] ?? $raw;
        return [trim($sm[1] ?? ''), $body];
    }

    private function seedSamarkandCityCatalog(): TourProduct
    {
        // Slug must contain 'samarkand-city' so the matchCatalog
        // slug-LIKE pattern picks it up via the 153457P2 → samarkand-city
        // mapping in ViatorApplyNewBookings::matchCatalog.
        $product = TourProduct::create([
            'title'             => 'Samarkand City Tour - Registan, Gur-e-Amir & Shah-i-Zinda',
            'slug'              => 'samarkand-city-tour',
            'region'            => 'samarkand',
            'is_active'         => true,
            'duration_days'     => 1,
            'duration_nights'   => 0,
        ]);
        TourProductDirection::create([
            'tour_product_id' => $product->id,
            'code'            => 'default',
            'name'            => 'Default route',
            'is_active'       => true,
        ]);
        return $product;
    }

    private function persistEmail(string $fixture): ViatorInboundEmail
    {
        [$subject, $body] = $this->loadFixture($fixture);
        $parsed = $this->parser->parse($subject, $body);

        return ViatorInboundEmail::create([
            'gmail_message_id'   => 'test-' . $fixture . '-' . uniqid(),
            'from_address'       => 'booking@t1.viator.com',
            'subject_raw'        => $subject,
            'email_type'         => $parsed['email_type'],
            'external_reference' => $parsed['external_reference'],
            'raw_body'           => $body,
            'parsed_payload'     => $parsed['parsed_payload'],
            'parsed_diff'        => null,
            'processing_status'  => ViatorInboundEmail::STATUS_PARSED,
            'processed_at'       => now(),
        ]);
    }

    /** @test */
    public function new_private_booking_with_catalog_match_creates_applied_inquiry(): void
    {
        // Slug-pattern matcher in ViatorApplyNewBookings looks for
        // 'samarkand-city' so the seeded product gets picked up
        // regardless of its auto-assigned ID.
        $this->seedSamarkandCityCatalog();

        $email = $this->persistEmail('new_private');
        $this->artisan('viator:apply-new-bookings')->assertExitCode(0);

        $email->refresh();
        $this->assertSame(ViatorInboundEmail::STATUS_APPLIED, $email->processing_status);
        $this->assertNotNull($email->booking_inquiry_id);

        $inquiry = $email->bookingInquiry;
        $this->assertSame('viator', $inquiry->source);
        $this->assertSame('BR-1390901059', $inquiry->external_reference);
        $this->assertSame('Test Guest A', $inquiry->customer_name);
        $this->assertSame(2, $inquiry->people_adults);
        $this->assertSame('2026-09-20', $inquiry->travel_date->toDateString());
        $this->assertEquals(97.50, (float) $inquiry->price_quoted);
    }

    /** @test */
    public function duplicate_external_reference_links_instead_of_duplicating(): void
    {
        $existing = BookingInquiry::create([
            'reference'          => BookingInquiry::generateReference(),
            'source'             => 'manual',
            'external_reference' => 'BR-1390901059', // matches the new_private fixture
            'tour_name_snapshot' => 'Manually inserted earlier',
            'customer_name'      => 'Manual Op',
            'customer_phone'     => '',
            'people_adults'      => 2,
            'travel_date'        => '2026-09-20',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
        ]);

        $email = $this->persistEmail('new_private');
        $this->artisan('viator:apply-new-bookings')->assertExitCode(0);

        $email->refresh();
        $this->assertSame(ViatorInboundEmail::STATUS_APPLIED, $email->processing_status);
        $this->assertSame($existing->id, $email->booking_inquiry_id, 'must LINK to existing, not duplicate');
        $this->assertSame(1, BookingInquiry::where('external_reference', 'BR-1390901059')->count(), 'no duplicate created');
    }

    /** @test */
    public function unmatched_catalog_product_routes_to_needs_review(): void
    {
        // Transfer (P5) has no catalog match in v1 — apply should still
        // create the inquiry (so it lands on the calendar) but flag
        // the email row for operator review with a clear reason.
        $email = $this->persistEmail('new_transfer');
        $this->artisan('viator:apply-new-bookings')->assertExitCode(0);

        $email->refresh();
        $this->assertSame(ViatorInboundEmail::STATUS_NEEDS_REVIEW, $email->processing_status);
        $this->assertNotNull($email->booking_inquiry_id);
        $this->assertStringContainsString('catalog', (string) $email->error_message);
    }

    /** @test */
    public function amendment_and_cancellation_are_never_touched_by_apply(): void
    {
        $amend  = $this->persistEmail('amended');
        $cancel = $this->persistEmail('cancelled');

        $this->artisan('viator:apply-new-bookings')->assertExitCode(0);

        $amend->refresh();
        $cancel->refresh();
        $this->assertSame(ViatorInboundEmail::STATUS_PARSED, $amend->processing_status, 'amendments stay parsed for human review');
        $this->assertSame(ViatorInboundEmail::STATUS_PARSED, $cancel->processing_status);
    }

}
