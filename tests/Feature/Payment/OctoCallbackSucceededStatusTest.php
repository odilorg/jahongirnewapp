<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use App\Models\OctoPaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: Octo's REAL production success status is "succeeded", not
 * "success". The original handler matched only `=== 'success'`, so genuine
 * paid callbacks fell into the failure branch — wiping the payment_link,
 * reverting awaiting_payment -> contacted, and stamping the attempt FAILED,
 * all while Octo had already captured the money.
 *
 * Root cause of the 2026-06-13 stuck-payment incident
 * (INQ-2026-000162 / INQ-2026-000163): every real online payment had to be
 * reconciled by hand because no real-sample test ever exercised "succeeded"
 * — the existing fixtures all used the synthetic string "success".
 *
 * These tests use a sanitized copy of a real Octo `succeeded` callback
 * payload (production logs 2026-05-01 inquiry_77 / 2026-06-13 inquiry_171),
 * with PAN masked and signature redacted.
 */
class OctoCallbackSucceededStatusTest extends TestCase
{
    use DatabaseTransactions;

    private const TRANSACTION = 'inquiry_777_RealSucc';

    /** Sanitized real "succeeded" callback shape (full-online payment). */
    private function succeededPayload(string $txn, int $sum): array
    {
        return [
            'shop_transaction_id' => $txn,
            'octo_payment_UUID'   => '54424e9a-4748-41f3-a2c1-27b0ab3753da',
            'status'              => 'succeeded',
            'signature'           => 'TEST-SIG-REDACTED',
            'hash_key'            => 'test-hash-key',
            'total_sum'           => $sum,
            'transfer_sum'        => $sum * 0.965,
            'refunded_sum'        => 0.0,
            'card_country'        => 'AE',
            'maskedPan'           => '552102** **** 9488',
            'currency'            => 'UZS',
            'card_vendor'         => 'Master Card',
            'rrn'                 => '612139657999',
            'riskLevel'           => -1,
            'payed_time'          => '2026-05-01 13:10:46',
            'card_type'           => 'internationalMC',
        ];
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'whatsapp',
            'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'customer_name'      => 'Real Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'real@e.st',
            'tour_name_snapshot' => 'Real Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
            'price_quoted'       => 150.00,
            'amount_online_usd'  => 150.00,
            'amount_cash_usd'    => 0.00,
            'payment_link'       => 'https://pay2.octo.uz/pay/real-uuid',
        ], $overrides));
    }

    private function makeAttempt(BookingInquiry $inquiry, string $txn): OctoPaymentAttempt
    {
        return OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => $txn,
            'amount_online_usd'       => 150.00,
            'price_quoted_at_attempt' => 150.00,
            'exchange_rate_used'      => 12026.48,
            'uzs_amount'              => 1_803_971,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ]);
    }

    /**
     * The core regression: a real "succeeded" callback must CONFIRM the
     * inquiry, record a GuestPayment, and stamp the attempt paid — exactly
     * like the synthetic "success" callback already does.
     */
    public function test_succeeded_status_confirms_inquiry_and_records_payment(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry, self::TRANSACTION);

        $this->postJson(route('octo.callback'), $this->succeededPayload(self::TRANSACTION, 1_803_971))
            ->assertOk();

        $inquiry->refresh();
        $attempt->refresh();

        $this->assertSame(BookingInquiry::STATUS_CONFIRMED, $inquiry->status, 'succeeded payment must confirm the inquiry');
        $this->assertSame(OctoPaymentAttempt::STATUS_PAID, $attempt->status, 'attempt must be stamped paid');
        $this->assertCount(1, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
        // The link must NOT be wiped on a successful payment.
        $this->assertNotNull($inquiry->payment_link, 'payment_link must survive a successful payment');
    }

    /**
     * A "succeeded" callback must NOT take the failure path, i.e. it must
     * never wipe the link or revert awaiting_payment -> contacted.
     * This is the precise inverted-handling bug.
     */
    public function test_succeeded_status_does_not_reset_link_or_revert_status(): void
    {
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, self::TRANSACTION);

        $this->postJson(route('octo.callback'), $this->succeededPayload(self::TRANSACTION, 1_803_971))
            ->assertOk();

        $inquiry->refresh();

        $this->assertNotSame(BookingInquiry::STATUS_CONTACTED, $inquiry->status, 'succeeded must not revert to contacted');
    }

    /** Case-insensitivity guard against future "Succeeded"/"SUCCESS" drift. */
    public function test_success_status_matching_is_case_insensitive(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry, self::TRANSACTION);

        $payload = $this->succeededPayload(self::TRANSACTION, 1_803_971);
        $payload['status'] = 'Succeeded';

        $this->postJson(route('octo.callback'), $payload)->assertOk();

        $inquiry->refresh();
        $attempt->refresh();

        $this->assertSame(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertSame(OctoPaymentAttempt::STATUS_PAID, $attempt->status);
    }
}
