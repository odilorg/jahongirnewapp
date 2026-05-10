<?php

declare(strict_types=1);

namespace Tests\Feature\BookingInquiries;

use App\Actions\BookingInquiries\EnrichWebsiteInquiryFromTourSlugAction;
use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression coverage for inquiry 109 (Andrea Sterrantino, 2026-05-10):
 * the calendar showed a 2-day Yurt Camp tour as a 1-day chip because
 * tour_product_id stayed NULL after intake. Without enrichment, the
 * calendar's duration ladder falls back to 1 day.
 */
class EnrichWebsiteInquiryFromTourSlugActionTest extends TestCase
{
    use DatabaseTransactions;

    private EnrichWebsiteInquiryFromTourSlugAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(EnrichWebsiteInquiryFromTourSlugAction::class);
    }

    private function makeProduct(string $slug, int $days = 2, string $tourType = 'private'): TourProduct
    {
        $product = TourProduct::create([
            'slug'            => $slug,
            'title'           => 'Test Tour '.uniqid(),
            'duration_days'   => $days,
            'duration_nights' => max(0, $days - 1),
            'tour_type'       => $tourType,
            'is_active'       => true,
        ]);

        TourProductDirection::create([
            'tour_product_id' => $product->id,
            'code'            => 'default',
            'name'            => 'Default Direction',
            'is_active'       => true,
            'sort_order'      => 0,
        ]);

        return $product;
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-ENR-'.uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Test Guest',
            'customer_email'     => 't@e.st',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour Snapshot',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
        ], $overrides));
    }

    public function test_known_slug_links_tour_product_direction_and_type(): void
    {
        $product = $this->makeProduct('yurt-camp-test', days: 2, tourType: 'private');
        $inquiry = $this->makeInquiry(['tour_slug' => 'yurt-camp-test']);

        $this->action->handle($inquiry);

        $inquiry->refresh();
        $this->assertSame($product->id, $inquiry->tour_product_id);
        $this->assertNotNull($inquiry->tour_product_direction_id);
        $this->assertSame('private', $inquiry->tour_type);
    }

    public function test_unknown_slug_is_graceful_no_op(): void
    {
        $inquiry = $this->makeInquiry(['tour_slug' => 'this-slug-does-not-exist']);

        $this->action->handle($inquiry);

        $inquiry->refresh();
        $this->assertNull($inquiry->tour_product_id, 'must not invent a wrong tour_product_id');
        $this->assertNull($inquiry->tour_product_direction_id);
    }

    public function test_missing_slug_is_no_op(): void
    {
        $inquiry = $this->makeInquiry(['tour_slug' => null]);

        $this->action->handle($inquiry);

        $inquiry->refresh();
        $this->assertNull($inquiry->tour_product_id);
    }

    public function test_does_not_clobber_operator_filled_fields(): void
    {
        // Idempotency / non-destructive: if an operator has already linked
        // the row to a different product (rare manual override), running
        // the Action again must NOT overwrite their choice.
        $productCorrect = $this->makeProduct('correct-slug', days: 3);
        $productOperator = $this->makeProduct('operator-pick', days: 5);

        $inquiry = $this->makeInquiry([
            'tour_slug'                 => 'correct-slug',
            'tour_product_id'           => $productOperator->id,
            'tour_product_direction_id' => $productOperator->directions()->first()->id,
            'tour_type'                 => 'group',
        ]);

        $this->action->handle($inquiry);

        $inquiry->refresh();
        $this->assertSame($productOperator->id, $inquiry->tour_product_id, 'operator override must be preserved');
        $this->assertSame('group', $inquiry->tour_type);
    }
}
