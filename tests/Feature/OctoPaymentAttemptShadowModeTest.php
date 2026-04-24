<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Payment\GeneratePaymentLinkAction;
use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use App\Services\OctoPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 1 shadow-mode contract: GeneratePaymentLinkAction writes one
 * attempt row per link created. No callback behavior change in this
 * phase — the callback test suite (OctoCallbackSplitPaymentTest) must
 * continue to pass unchanged.
 */
class OctoPaymentAttemptShadowModeTest extends TestCase
{
    use DatabaseTransactions;

    private GeneratePaymentLinkAction $action;
    private MockInterface $octo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->octo = $this->mock(OctoPaymentService::class);
        $this->octo->shouldReceive('createPaymentLinkForInquiry')
            ->byDefault()
            ->andReturn([
                'url'            => 'https://pay2.octo.uz/pay/mock-uuid',
                'transaction_id' => 'inquiry_mock_abc123',
                'uzs_amount'     => 7_500_000,
            ]);

        $this->action = app(GeneratePaymentLinkAction::class);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
        ], $overrides));
    }

    public function test_generate_creates_one_active_attempt_row(): void
    {
        $inquiry = $this->makeInquiry(['price_quoted' => 150.00]);

        $this->action->execute($inquiry, total: 150.00, online: 150.00);

        $this->assertCount(1, $inquiry->paymentAttempts);

        /** @var OctoPaymentAttempt $attempt */
        $attempt = $inquiry->paymentAttempts->first();
        $this->assertEquals('inquiry_mock_abc123', $attempt->transaction_id);
        $this->assertEquals(150.00, (float) $attempt->amount_online_usd);
        $this->assertEquals(150.00, (float) $attempt->price_quoted_at_attempt);
        $this->assertEquals(7_500_000, (int) $attempt->uzs_amount);
        // 7_500_000 / 150 = 50_000.0000
        $this->assertEquals(50000.0000, (float) $attempt->exchange_rate_used);
        $this->assertEquals(OctoPaymentAttempt::STATUS_ACTIVE, $attempt->status);
        $this->assertNull($attempt->superseded_at);
    }

    public function test_generate_attempt_row_matches_inquiry_pointer(): void
    {
        $inquiry = $this->makeInquiry(['price_quoted' => 150.00]);

        $this->action->execute($inquiry, total: 150.00, online: 60.00);
        $inquiry->refresh();

        // Inquiry pointer and attempt row agree on the active txn id —
        // Phase 2 callback flip depends on this invariant.
        $this->assertEquals(
            $inquiry->octo_transaction_id,
            $inquiry->activePaymentAttempt->transaction_id,
        );
    }

    public function test_partial_payment_attempt_stores_online_portion_not_total(): void
    {
        $inquiry = $this->makeInquiry(['price_quoted' => 150.00]);

        $this->action->execute($inquiry, total: 150.00, online: 60.00);

        $attempt = $inquiry->paymentAttempts()->first();
        // The whole point of per-attempt snapshots: record what was ACTUALLY
        // charged (60), not the total quote (150). Phase 4 callback reads
        // this to record the correct GuestPayment amount.
        $this->assertEquals(60.00, (float) $attempt->amount_online_usd);
        $this->assertEquals(150.00, (float) $attempt->price_quoted_at_attempt);
    }
}
