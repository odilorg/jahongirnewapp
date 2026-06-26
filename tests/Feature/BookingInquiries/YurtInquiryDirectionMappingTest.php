<?php

declare(strict_types=1);

namespace Tests\Feature\BookingInquiries;

use App\Actions\BookingInquiries\EnrichWebsiteInquiryFromTourSlugAction;
use App\Http\Requests\StoreBookingInquiryRequest;
use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Steps 8–9: the yurt hub pages on jahongir-travel.uz send tour_slug,
 * direction and booking_type. This verifies the CRM intake as one unit:
 *
 *  - StoreBookingInquiryRequest accepts the optional fields without 422
 *    and maps booking_type → tour_type (ignoring unrecognised values).
 *  - EnrichWebsiteInquiryFromTourSlugAction resolves a requested route
 *    against the catalog, falls back fail-soft for unknown routes, and
 *    preserves the raw requested route in message for the operator.
 *
 * Backward compatibility: old payloads with none of these fields, and
 * Action calls with no direction argument, behave exactly as before.
 */
class YurtInquiryDirectionMappingTest extends TestCase
{
    use DatabaseTransactions;

    private EnrichWebsiteInquiryFromTourSlugAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(EnrichWebsiteInquiryFromTourSlugAction::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function buildRequest(array $payload): StoreBookingInquiryRequest
    {
        $req = StoreBookingInquiryRequest::create('/api/v1/inquiries', 'POST', $payload);
        $req->setContainer(app());
        $req->setRedirector(app(\Illuminate\Routing\Redirector::class));
        $req->validateResolved();

        return $req;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'tour_name_snapshot' => 'Yurt Camp Tour',
            'customer_name'      => 'Guest',
            'customer_email'     => 'g@e.test',
            'customer_phone'     => '+998901234567',
            'people_adults'      => 2,
        ], $overrides);
    }

    /** Create the yurt product with the given direction codes (in order). */
    private function makeProductWithDirections(string $slug, array $codes): TourProduct
    {
        $product = TourProduct::create([
            'slug'            => $slug,
            'title'           => 'Yurt Camp '.uniqid(),
            'region'          => 'nuratau',
            'duration_days'   => 2,
            'duration_nights' => 1,
            'tour_type'       => 'private',
            'is_active'       => true,
        ]);

        $sort = 0;
        foreach ($codes as $code) {
            TourProductDirection::create([
                'tour_product_id' => $product->id,
                'code'            => $code,
                'name'            => ucfirst($code),
                'is_active'       => true,
                'sort_order'      => $sort++,
            ]);
        }

        return $product;
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-YRT-'.uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Guest',
            'customer_email'     => 'g@e.test',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Yurt Camp Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
        ], $overrides));
    }

    // ── validation layer (StoreBookingInquiryRequest) ────────────────────

    public function test_request_accepts_new_fields_and_maps_booking_type(): void
    {
        $req = $this->buildRequest($this->basePayload([
            'tour_slug'    => 'yurt-camp-tour',
            'direction'    => 'sam-bukhara',
            'booking_type' => 'group',
        ]));

        $this->assertSame('sam-bukhara', $req->requestedDirectionCode());
        $this->assertSame('group', $req->toInquiryData()['tour_type']);
    }

    public function test_unrecognised_booking_type_does_not_break_and_yields_null_type(): void
    {
        // An unrecognised (but well-formed) booking_type must NOT 422 and must
        // NOT be stored as tour_type — the enrichment Action then applies the
        // catalog default instead.
        $req = $this->buildRequest($this->basePayload([
            'booking_type' => 'family',
        ]));

        $this->assertNull($req->toInquiryData()['tour_type']);
    }

    public function test_old_payload_without_new_fields_is_unchanged(): void
    {
        $req  = $this->buildRequest($this->basePayload());
        $data = $req->toInquiryData();

        $this->assertNull($req->requestedDirectionCode());
        $this->assertNull($data['tour_type']);
    }

    // ── mapping layer (EnrichWebsiteInquiryFromTourSlugAction) ────────────

    public function test_requested_direction_code_wins_over_sort_order_default(): void
    {
        $product = $this->makeProductWithDirections('yurt-camp-tour', ['sam-bukhara', 'sam-sam']);
        $samSam  = $product->directions()->where('code', 'sam-sam')->first();
        $inquiry = $this->makeInquiry(['tour_slug' => 'yurt-camp-tour']);

        // Requesting the sort_order=1 route must override the sort_order=0 default.
        $this->action->handle($inquiry, 'sam-sam');

        $inquiry->refresh();
        $this->assertSame($product->id, $inquiry->tour_product_id);
        $this->assertSame($samSam->id, $inquiry->tour_product_direction_id);
    }

    public function test_full_yurt_payload_maps_product_direction_and_type(): void
    {
        $product = $this->makeProductWithDirections('yurt-camp-tour', ['sam-bukhara']);
        $samBuk  = $product->directions()->where('code', 'sam-bukhara')->first();

        // Simulate the controller path: request maps booking_type → tour_type,
        // then the Action links product + direction.
        $req     = $this->buildRequest($this->basePayload([
            'tour_slug'    => 'yurt-camp-tour',
            'direction'    => 'sam-bukhara',
            'booking_type' => 'private',
        ]));
        $inquiry = $this->makeInquiry(array_merge($req->toInquiryData(), [
            'reference'    => 'INQ-YRT-FULL-'.uniqid(),
            'submitted_at' => now(),
        ]));

        $this->action->handle($inquiry, $req->requestedDirectionCode());

        $inquiry->refresh();
        $this->assertSame($product->id, $inquiry->tour_product_id);
        $this->assertSame($samBuk->id, $inquiry->tour_product_direction_id);
        $this->assertSame('private', $inquiry->tour_type);
    }

    public function test_unmapped_direction_is_failsoft_and_preserves_raw_route(): void
    {
        // Only sam-bukhara exists in the catalog; the guest asks for bukhara-sam.
        $product = $this->makeProductWithDirections('yurt-camp-tour', ['sam-bukhara']);
        $samBuk  = $product->directions()->where('code', 'sam-bukhara')->first();
        $inquiry = $this->makeInquiry(['tour_slug' => 'yurt-camp-tour']);

        $this->action->handle($inquiry, 'bukhara-sam');

        $inquiry->refresh();
        // Lead is still created and linked to the default direction (no error).
        $this->assertSame($product->id, $inquiry->tour_product_id);
        $this->assertSame($samBuk->id, $inquiry->tour_product_direction_id);
        // The raw requested route is preserved for the operator.
        $this->assertStringContainsString('bukhara-sam', (string) $inquiry->message);
    }

    public function test_unmapped_direction_note_is_appended_once(): void
    {
        $this->makeProductWithDirections('yurt-camp-tour', ['sam-bukhara']);
        $inquiry = $this->makeInquiry(['tour_slug' => 'yurt-camp-tour']);

        $this->action->handle($inquiry, 'sam-sam');
        $this->action->handle($inquiry->refresh(), 'sam-sam');

        $inquiry->refresh();
        $this->assertSame(1, substr_count((string) $inquiry->message, 'sam-sam'));
    }

    public function test_no_direction_argument_keeps_sort_order_default(): void
    {
        // Backward compatibility: calling handle() with no direction code must
        // reproduce the original sort_order behaviour.
        $product = $this->makeProductWithDirections('yurt-camp-tour', ['sam-bukhara', 'sam-sam']);
        $samBuk  = $product->directions()->where('code', 'sam-bukhara')->first();
        $inquiry = $this->makeInquiry(['tour_slug' => 'yurt-camp-tour']);

        $this->action->handle($inquiry);

        $inquiry->refresh();
        $this->assertSame($samBuk->id, $inquiry->tour_product_direction_id);
    }
}
