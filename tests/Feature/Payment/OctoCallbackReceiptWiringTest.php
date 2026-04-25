<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\SendReceiptAction;
use App\Actions\Calendar\Support\CalendarActionResult;
use App\Mail\BookingPaymentReceiptMail;
use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verifies OctoCallbackController wires SendReceiptAction correctly.
 *
 *  1. Successful payment → SendReceiptAction is invoked.
 *  2. SendReceiptAction exception does NOT cause webhook to return non-200.
 *  3. Receipt NOT sent for terminal-status (cancelled/spam) path.
 *  4. Receipt NOT sent for failed payment.
 *  5. Receipt NOT sent for superseded/terminal attempt.
 *  6. Duplicate callback (already paid) does NOT re-send receipt.
 */
class OctoCallbackReceiptWiringTest extends TestCase
{
    use DatabaseTransactions;

    private const TXN = 'inquiry_receipt_wiring_test';

    private function postCallback(string $txn, string $status = 'success', int $sum = 7_000_000): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('octo.callback'), [
            'shop_transaction_id' => $txn,
            'status'              => $status,
            'total_sum'           => $sum,
            'signature'           => 'TEST-SIG-PLACEHOLDER',
            'hash_key'            => 'test-hash-key',
        ]);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'customer_name'      => 'Receipt Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'receipt@test.example',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
            'price_quoted'       => 100.00,
            'amount_online_usd'  => 100.00,
            'amount_cash_usd'    => 0.00,
        ], $overrides));
    }

    private function makeAttempt(BookingInquiry $inquiry, string $txn, array $overrides = []): OctoPaymentAttempt
    {
        return OctoPaymentAttempt::create(array_merge([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => $txn,
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ], $overrides));
    }

    /** 1. Successful payment → SendReceiptAction executes and stamps receipt_sent_at. */
    public function test_successful_payment_invokes_receipt_action(): void
    {
        Mail::fake();
        $txn = self::TXN . '_' . uniqid();
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, $txn);

        $this->postCallback($txn)->assertOk();

        // SEND_GUEST_MESSAGES=false (phpunit.xml) → WA skipped via kill switch.
        // Mail::fake() intercepts email → at least one channel succeeds.
        // In the HTTP test stack the mail may land in sent or queued depending on
        // how the MailFactory resolves; check both collections.
        $receiptSentOrQueued = \Illuminate\Support\Facades\Mail::sent(BookingPaymentReceiptMail::class)->count() > 0
            || \Illuminate\Support\Facades\Mail::queued(BookingPaymentReceiptMail::class)->count() > 0;
        $this->assertTrue($receiptSentOrQueued, 'BookingPaymentReceiptMail was not sent or queued');
        $this->assertNotNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 2. SendReceiptAction exception does NOT cause 5xx — webhook always returns 200. */
    public function test_receipt_exception_does_not_fail_webhook(): void
    {
        Mail::fake();
        $txn = self::TXN . '_exc_' . uniqid();
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, $txn);

        // Bind a spy that throws inside execute()
        $this->app->bind(SendReceiptAction::class, function () {
            $mock = $this->createMock(SendReceiptAction::class);
            $mock->method('execute')->willThrowException(new \RuntimeException('receipt exploded'));
            return $mock;
        });

        $this->postCallback($txn)->assertOk();
    }

    /** 3. Terminal-status (cancelled) payment does NOT send receipt. */
    public function test_terminal_status_payment_does_not_send_receipt(): void
    {
        Mail::fake();
        $txn = self::TXN . '_term_' . uniqid();
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_CANCELLED]);
        $this->makeAttempt($inquiry, $txn);

        $this->postCallback($txn)->assertOk()->assertJson(['note' => 'terminal_status_review_required']);

        Mail::assertNotSent(BookingPaymentReceiptMail::class);
    }

    /** 4. Failed payment does NOT send receipt. */
    public function test_failed_payment_does_not_send_receipt(): void
    {
        Mail::fake();
        $txn = self::TXN . '_fail_' . uniqid();
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, $txn);

        $this->postCallback($txn, 'failed')->assertOk();

        Mail::assertNotSent(BookingPaymentReceiptMail::class);
        $this->assertNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 5. Superseded attempt does NOT send receipt. */
    public function test_superseded_attempt_does_not_send_receipt(): void
    {
        Mail::fake();
        $txn = self::TXN . '_sup_' . uniqid();
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, $txn, ['status' => OctoPaymentAttempt::STATUS_SUPERSEDED]);

        $this->postCallback($txn)->assertOk()->assertJsonFragment(['note' => 'attempt_terminal_superseded']);

        Mail::assertNotSent(BookingPaymentReceiptMail::class);
    }

    /** 6. Duplicate callback (already paid) does NOT re-send receipt. */
    public function test_duplicate_callback_does_not_resend_receipt(): void
    {
        Mail::fake();
        $txn = self::TXN . '_dup_' . uniqid();
        $inquiry = $this->makeInquiry([
            'status'           => BookingInquiry::STATUS_CONFIRMED,
            'paid_at'          => now()->subHour(),
            'receipt_sent_at'  => now()->subHour(),
        ]);
        $this->makeAttempt($inquiry, $txn);

        $this->postCallback($txn)->assertOk()->assertJson(['note' => 'already_paid']);

        // No mail sent because the already_paid guard fires before receipt
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
    }
}
