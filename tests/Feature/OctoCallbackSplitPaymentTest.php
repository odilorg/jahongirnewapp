<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the split-payment callback fix.
 *
 * Before split-payment: the callback hardcoded price_quoted as the received
 * amount, which meant a partial online payment would silently over-credit
 * the guest and trigger paid_at / "fully paid" state. This suite locks the
 * correct behavior — record amount_online_usd, fall back to price_quoted
 * for legacy rows with no split data.
 */
class OctoCallbackSplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'price_quoted'       => 150.00,
            'currency'           => 'USD',
            'payment_method'     => BookingInquiry::PAYMENT_ONLINE,
            'payment_link'       => 'https://pay2.octo.uz/pay/test-uuid',
            'octo_transaction_id' => 'inquiry_1_abc123',
            'submitted_at'       => now(),
        ], $overrides));
    }

    private function fireCallback(string $transactionId, string $status = 'success', int $uzsSum = 10000): \Illuminate\Testing\TestResponse
    {
        return $this->post('/octo/callback', [
            'shop_transaction_id' => $transactionId,
            'status'              => $status,
            'total_sum'           => $uzsSum,
        ]);
    }

    public function test_partial_payment_records_only_online_amount(): void
    {
        $inquiry = $this->makeInquiry([
            'amount_online_usd' => 60.00,
            'amount_cash_usd'   => 90.00,
            'payment_split'     => BookingInquiry::PAYMENT_SPLIT_PARTIAL,
            'octo_transaction_id' => 'inquiry_partial_test',
        ]);

        $this->fireCallback('inquiry_partial_test')->assertOk();

        $payment = GuestPayment::where('booking_inquiry_id', $inquiry->id)->firstOrFail();

        $this->assertEquals(60.00, (float) $payment->amount, 'Partial payment must record amount_online_usd, NOT price_quoted. This is the whole point of the fix — recording $150 here would silently over-credit the guest.');
        $this->assertEquals(GuestPayment::TYPE_BALANCE, $payment->payment_type, 'Partial payment should flag as TYPE_BALANCE so reporting distinguishes it from a full payment.');
        $this->assertEquals('octo', $payment->payment_method);
        $this->assertEquals('inquiry_partial_test', $payment->reference);
    }

    public function test_full_online_payment_records_full_amount(): void
    {
        $inquiry = $this->makeInquiry([
            'amount_online_usd' => 150.00,
            'amount_cash_usd'   => 0.00,
            'payment_split'     => BookingInquiry::PAYMENT_SPLIT_FULL,
            'octo_transaction_id' => 'inquiry_full_test',
        ]);

        $this->fireCallback('inquiry_full_test')->assertOk();

        $payment = GuestPayment::where('booking_inquiry_id', $inquiry->id)->firstOrFail();

        $this->assertEquals(150.00, (float) $payment->amount);
        $this->assertEquals(GuestPayment::TYPE_FULL, $payment->payment_type, 'Full online payment must flag as TYPE_FULL.');
    }

    public function test_legacy_inquiry_without_split_fields_falls_back_to_price_quoted(): void
    {
        // Simulates a row that existed before the split-payment migration
        // ran (backfill skipped this one because e.g. it was cancelled at
        // the time, then later revived). No amount_online_usd populated.
        $inquiry = $this->makeInquiry([
            'amount_online_usd' => null,
            'amount_cash_usd'   => null,
            'payment_split'     => BookingInquiry::PAYMENT_SPLIT_FULL,
            'octo_transaction_id' => 'inquiry_legacy_test',
        ]);

        $this->fireCallback('inquiry_legacy_test')->assertOk();

        $payment = GuestPayment::where('booking_inquiry_id', $inquiry->id)->firstOrFail();

        $this->assertEquals(150.00, (float) $payment->amount, 'Legacy rows must fall back to price_quoted so pre-migration data still reconciles.');
        $this->assertEquals(GuestPayment::TYPE_FULL, $payment->payment_type);
    }

    public function test_callback_confirms_inquiry_regardless_of_split(): void
    {
        $partial = $this->makeInquiry([
            'amount_online_usd'   => 60.00,
            'amount_cash_usd'     => 90.00,
            'payment_split'       => BookingInquiry::PAYMENT_SPLIT_PARTIAL,
            'octo_transaction_id' => 'inquiry_confirm_partial',
        ]);

        $this->fireCallback('inquiry_confirm_partial')->assertOk();

        $partial->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $partial->status, 'Successful Octo payment on a partial inquiry must still confirm the booking — cash is collected separately at pickup.');
        $this->assertNotNull($partial->confirmed_at);
    }
}
