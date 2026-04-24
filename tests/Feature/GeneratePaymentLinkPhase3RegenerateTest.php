<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Payment\GeneratePaymentLinkAction;
use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use App\Services\OctoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 3 contract: GeneratePaymentLinkAction supersedes the current active
 * attempt and creates a fresh one every time it is called — whether this is
 * the first generation or a regeneration.
 *
 * Invariants locked here:
 *  - First call: one active attempt created, no superseded row.
 *  - Regeneration: prior active attempt becomes superseded, new active
 *    attempt is created, inquiry pointer updated to new transaction_id.
 *  - Only STATUS_ACTIVE rows are superseded; paid/failed rows are untouched.
 *  - is_regeneration flag in return value reflects whether a prior attempt
 *    was superseded.
 *  - audit_label mentions "superseded" on regeneration.
 *  - Multiple regenerations accumulate superseded rows correctly.
 */
class GeneratePaymentLinkPhase3RegenerateTest extends TestCase
{
    use RefreshDatabase;

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
                'transaction_id' => 'inquiry_5_newABC',
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
            'customer_email'     => 't@e.st',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
            'price_quoted'       => 100.00,
            'amount_online_usd'  => 100.00,
            'amount_cash_usd'    => 0.00,
        ], $overrides));
    }

    /** First call: one active attempt, no superseded rows. */
    public function test_first_generation_creates_one_active_attempt(): void
    {
        $inquiry = $this->makeInquiry();

        $result = $this->action->execute($inquiry, 100.00, 100.00);

        $this->assertFalse($result['is_regeneration']);
        $this->assertStringNotContainsString('superseded', $result['audit_label']);

        $attempts = OctoPaymentAttempt::where('inquiry_id', $inquiry->id)->get();
        $this->assertCount(1, $attempts);
        $this->assertEquals(OctoPaymentAttempt::STATUS_ACTIVE, $attempts->first()->status);
    }

    /** Regeneration: prior active attempt superseded, new active attempt created. */
    public function test_regeneration_supersedes_prior_active_attempt(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_AWAITING_PAYMENT]);

        $prior = OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => 'inquiry_5_oldTXN',
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ]);
        $inquiry->update([
            'payment_link'        => 'https://pay2.octo.uz/pay/old-uuid',
            'octo_transaction_id' => 'inquiry_5_oldTXN',
        ]);

        $result = $this->action->execute($inquiry, 100.00, 100.00);

        $this->assertTrue($result['is_regeneration']);
        $this->assertStringContainsString('superseded', $result['audit_label']);
        $this->assertEquals('inquiry_5_newABC', $result['transaction_id']);

        $prior->refresh();
        $this->assertEquals(OctoPaymentAttempt::STATUS_SUPERSEDED, $prior->status);

        $newAttempt = OctoPaymentAttempt::where('inquiry_id', $inquiry->id)
            ->where('status', OctoPaymentAttempt::STATUS_ACTIVE)
            ->first();
        $this->assertNotNull($newAttempt);
        $this->assertEquals('inquiry_5_newABC', $newAttempt->transaction_id);

        $inquiry->refresh();
        $this->assertEquals('inquiry_5_newABC', $inquiry->octo_transaction_id);
    }

    /** Paid/failed attempts are untouched during regeneration. */
    public function test_regeneration_does_not_touch_terminal_attempts(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_AWAITING_PAYMENT]);

        $paid = OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => 'inquiry_5_paidTXN',
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => OctoPaymentAttempt::STATUS_PAID,
        ]);

        $active = OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => 'inquiry_5_activeTXN',
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ]);
        $inquiry->update([
            'payment_link'        => 'https://pay2.octo.uz/pay/active-uuid',
            'octo_transaction_id' => 'inquiry_5_activeTXN',
        ]);

        $this->action->execute($inquiry, 100.00, 100.00);

        $paid->refresh();
        $active->refresh();

        $this->assertEquals(OctoPaymentAttempt::STATUS_PAID, $paid->status, 'Paid attempt must remain paid');
        $this->assertEquals(OctoPaymentAttempt::STATUS_SUPERSEDED, $active->status, 'Prior active must be superseded');
    }

    /** Multiple regenerations accumulate superseded rows. */
    public function test_multiple_regenerations_accumulate_superseded_rows(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_AWAITING_PAYMENT]);

        $this->octo->shouldReceive('createPaymentLinkForInquiry')
            ->times(3)
            ->andReturnValues([
                ['url' => 'https://pay.uz/1', 'transaction_id' => 'inquiry_5_txn1', 'uzs_amount' => 1_000_000],
                ['url' => 'https://pay.uz/2', 'transaction_id' => 'inquiry_5_txn2', 'uzs_amount' => 1_000_000],
                ['url' => 'https://pay.uz/3', 'transaction_id' => 'inquiry_5_txn3', 'uzs_amount' => 1_000_000],
            ]);

        $this->action->execute($inquiry, 100.00, 100.00);
        $this->action->execute($inquiry, 100.00, 100.00);
        $this->action->execute($inquiry, 100.00, 100.00);

        $attempts = OctoPaymentAttempt::where('inquiry_id', $inquiry->id)->get();
        $this->assertCount(3, $attempts);
        $this->assertCount(2, $attempts->where('status', OctoPaymentAttempt::STATUS_SUPERSEDED));
        $this->assertCount(1, $attempts->where('status', OctoPaymentAttempt::STATUS_ACTIVE));

        $inquiry->refresh();
        $this->assertEquals('inquiry_5_txn3', $inquiry->octo_transaction_id);
    }

    /** is_regeneration=false on first call even when inquiry has no payment_link yet. */
    public function test_is_regeneration_false_when_no_prior_attempt(): void
    {
        $inquiry = $this->makeInquiry();

        $result = $this->action->execute($inquiry, 100.00, 100.00);

        $this->assertFalse($result['is_regeneration']);
    }

    /** Audit label on regeneration includes "superseded" for operator visibility. */
    public function test_audit_label_on_regen_includes_superseded(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_AWAITING_PAYMENT]);

        OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => 'inquiry_5_old',
            'amount_online_usd'       => 80.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_000_000,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ]);
        $inquiry->update([
            'payment_link'        => 'https://pay.uz/old',
            'octo_transaction_id' => 'inquiry_5_old',
        ]);

        // Partial payment regeneration
        $this->octo->shouldReceive('createPaymentLinkForInquiry')
            ->once()
            ->andReturn(['url' => 'https://pay.uz/new', 'transaction_id' => 'inquiry_5_new2', 'uzs_amount' => 1_000_000]);

        $result = $this->action->execute($inquiry, 100.00, 80.00);

        $this->assertStringContainsString('superseded', $result['audit_label']);
        $this->assertStringContainsString('regenerated', $result['audit_label']);
    }
}
