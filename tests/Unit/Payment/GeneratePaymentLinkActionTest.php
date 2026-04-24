<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Actions\Payment\GeneratePaymentLinkAction;
use App\Models\BookingInquiry;
use App\Services\OctoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Validation + race-guard contract for GeneratePaymentLinkAction.
 *
 * The action is the single chokepoint for payment-link generation, so every
 * guard it enforces becomes the whole system's guard. Test each rule
 * explicitly — a silent regression here ships bad links to real guests.
 */
class GeneratePaymentLinkActionTest extends TestCase
{
    use RefreshDatabase;

    private GeneratePaymentLinkAction $action;
    private MockInterface $octo;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Octo so tests never touch the real API. Default stub is a
        // happy response — individual tests can expect-never where the
        // guard should short-circuit before Octo is called.
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

    public function test_rejects_online_amount_below_minimum(): void
    {
        $this->octo->shouldNotReceive('createPaymentLinkForInquiry');
        $inquiry = $this->makeInquiry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least $' . BookingInquiry::MIN_ONLINE_USD);

        // Below $10 floor — blocks tiny links that waste transaction fees.
        $this->action->execute($inquiry, total: 100.00, online: 5.00);
    }

    public function test_rejects_online_amount_above_total(): void
    {
        $this->octo->shouldNotReceive('createPaymentLinkForInquiry');
        $inquiry = $this->makeInquiry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed total');

        // Charging more online than the agreed tour price would over-collect
        // on the link, breaking the ledger invariant.
        $this->action->execute($inquiry, total: 100.00, online: 150.00);
    }

    public function test_rejects_zero_total_price(): void
    {
        $this->octo->shouldNotReceive('createPaymentLinkForInquiry');
        $inquiry = $this->makeInquiry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than 0');

        $this->action->execute($inquiry, total: 0.00, online: 50.00);
    }

    public function test_refuses_when_payment_link_already_exists(): void
    {
        // Race-guard: defense in depth against two operators clicking
        // "Generate" simultaneously. If we don't refuse, the second caller
        // would orphan the first Octo transaction (webhook lookup breaks).
        $this->octo->shouldNotReceive('createPaymentLinkForInquiry');

        $inquiry = $this->makeInquiry([
            'payment_link'        => 'https://pay2.octo.uz/pay/existing-uuid',
            'octo_transaction_id' => 'inquiry_existing_abc',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->action->execute($inquiry, total: 150.00, online: 150.00);
    }

    public function test_happy_path_full_online_persists_and_classifies_as_full(): void
    {
        $inquiry = $this->makeInquiry(['price_quoted' => 150.00]);

        $result = $this->action->execute($inquiry, total: 150.00, online: 150.00);

        $this->assertEquals(BookingInquiry::PAYMENT_SPLIT_FULL, $result['split']);
        $this->assertEquals('wa_generate_and_send', $result['template_key']);

        $inquiry->refresh();
        $this->assertEquals(150.00, (float) $inquiry->amount_online_usd);
        $this->assertEquals(0.00, (float) $inquiry->amount_cash_usd);
        $this->assertEquals(BookingInquiry::PAYMENT_SPLIT_FULL, $inquiry->payment_split);
        $this->assertEquals(BookingInquiry::STATUS_AWAITING_PAYMENT, $inquiry->status);
        $this->assertNotNull($inquiry->payment_link);
    }

    public function test_happy_path_partial_persists_and_classifies_as_partial(): void
    {
        $inquiry = $this->makeInquiry(['price_quoted' => 150.00]);

        $result = $this->action->execute($inquiry, total: 150.00, online: 60.00);

        $this->assertEquals(BookingInquiry::PAYMENT_SPLIT_PARTIAL, $result['split']);
        $this->assertEquals('wa_generate_and_send_partial', $result['template_key']);
        $this->assertArrayHasKey('total', $result['template_extras']);
        $this->assertArrayHasKey('online', $result['template_extras']);
        $this->assertArrayHasKey('cash', $result['template_extras']);
        $this->assertArrayHasKey('link', $result['template_extras']);

        $inquiry->refresh();
        $this->assertEquals(60.00, (float) $inquiry->amount_online_usd);
        $this->assertEquals(90.00, (float) $inquiry->amount_cash_usd);
        $this->assertEquals(BookingInquiry::PAYMENT_SPLIT_PARTIAL, $inquiry->payment_split);
        // price_quoted is kept as source of truth; the "Total tour price"
        // field in the modal IS the quote.
        $this->assertEquals(150.00, (float) $inquiry->price_quoted);
    }
}
