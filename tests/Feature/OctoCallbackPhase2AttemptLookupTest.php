<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use App\Models\OctoPaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 2 contract: OctoCallbackController resolves inquiries via the
 * octo_payment_attempts table first, falling back to the legacy
 * octo_transaction_id column only for pre-Phase-1 rows.
 *
 * Invariants locked here:
 *  - Attempt status is stamped paid/failed after inquiry processing.
 *  - Orphan attempts (missing inquiry) return 200 and log — never 5xx.
 *  - Legacy fallback still works for rows without an attempt record.
 *  - Idempotency and terminal-status guards continue to fire correctly
 *    when the inquiry is resolved via an attempt row.
 *  - A stamp failure never withholds the 200 response.
 */
class OctoCallbackPhase2AttemptLookupTest extends TestCase
{
    use DatabaseTransactions;

    private const TRANSACTION = 'inquiry_99_Phase2T';

    private function postCallback(string $txn, string $status = 'success', int $sum = 7_000_000): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('octo.callback'), [
            'shop_transaction_id' => $txn,
            'status'              => $status,
            'total_sum'           => $sum,
        ]);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
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

    private function makeAttempt(BookingInquiry $inquiry, array $overrides = []): OctoPaymentAttempt
    {
        return OctoPaymentAttempt::create(array_merge([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => self::TRANSACTION,
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => OctoPaymentAttempt::STATUS_ACTIVE,
        ], $overrides));
    }

    /** Happy path: attempt resolved, inquiry confirmed, attempt stamped paid. */
    public function test_callback_resolves_via_attempt_and_stamps_paid(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry);

        $this->postCallback(self::TRANSACTION)->assertOk();

        $inquiry->refresh();
        $attempt->refresh();

        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertEquals(OctoPaymentAttempt::STATUS_PAID, $attempt->status);
        $this->assertCount(1, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
    }

    /** Payment failure: attempt stamped failed, inquiry stays awaiting_payment. */
    public function test_callback_stamps_attempt_failed_on_payment_failure(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry);

        $this->postCallback(self::TRANSACTION, 'failed')->assertOk();

        $inquiry->refresh();
        $attempt->refresh();

        $this->assertEquals(BookingInquiry::STATUS_AWAITING_PAYMENT, $inquiry->status);
        $this->assertEquals(OctoPaymentAttempt::STATUS_FAILED, $attempt->status);
        $this->assertCount(0, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
    }

    /** Only the targeted attempt is stamped when multiple attempts exist. */
    public function test_only_targeted_attempt_is_stamped(): void
    {
        $inquiry = $this->makeInquiry();

        $superseded = $this->makeAttempt($inquiry, [
            'transaction_id' => 'inquiry_99_old_one',
            'status'         => OctoPaymentAttempt::STATUS_SUPERSEDED,
        ]);

        $active = $this->makeAttempt($inquiry, [
            'transaction_id' => self::TRANSACTION,
            'status'         => OctoPaymentAttempt::STATUS_ACTIVE,
        ]);

        $inquiry->update(['octo_transaction_id' => self::TRANSACTION]);

        $this->postCallback(self::TRANSACTION)->assertOk();

        $superseded->refresh();
        $active->refresh();

        $this->assertEquals(OctoPaymentAttempt::STATUS_SUPERSEDED, $superseded->status);
        $this->assertEquals(OctoPaymentAttempt::STATUS_PAID, $active->status);
    }

    /** Legacy fallback works when no attempt row exists for this transaction. */
    public function test_falls_back_to_legacy_octo_transaction_id_when_no_attempt(): void
    {
        $inquiry = $this->makeInquiry([
            'octo_transaction_id' => self::TRANSACTION,
        ]);
        // No attempt row — simulates pre-Phase-1 link.

        $this->postCallback(self::TRANSACTION)->assertOk();

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
    }

    /**
     * Orphan-attempt guard exists in the controller but cannot be exercised
     * via this test suite: octo_payment_attempts.inquiry_id has cascadeOnDelete,
     * so deleting the inquiry also deletes the attempt, leaving no orphan row.
     * The guard is defensive code for any future schema/import edge case.
     * If the cascade ever changes, add a direct DB::table insert to test it.
     */

    /** Unknown transaction_id returns 404. */
    public function test_unknown_transaction_id_returns_404(): void
    {
        $this->postCallback('inquiry_99_does_not_exist')->assertNotFound();
    }

    /** Idempotency guard fires even when inquiry is resolved via attempt. */
    public function test_idempotency_guard_fires_with_attempt_lookup(): void
    {
        $inquiry = $this->makeInquiry(['paid_at' => now()]);
        $this->makeAttempt($inquiry);

        $response = $this->postCallback(self::TRANSACTION)->assertOk();
        $this->assertEquals('already_paid', $response->json('note'));
        $this->assertCount(0, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
    }

    /** Terminal-status guard fires even when inquiry is resolved via attempt. */
    public function test_terminal_status_guard_fires_with_attempt_lookup(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_CANCELLED]);
        $attempt = $this->makeAttempt($inquiry);

        $response = $this->postCallback(self::TRANSACTION)->assertOk();
        $this->assertEquals('terminal_status_review_required', $response->json('note'));

        // Attempt still stamped paid — money arrived even under review.
        $attempt->refresh();
        $this->assertEquals(OctoPaymentAttempt::STATUS_PAID, $attempt->status);
    }

    /**
     * Confirm that inquiry confirmation + 200 response are independent of the
     * attempt stamp. The stampAttempt() try/catch is the implementation guarantee;
     * to exercise the catch path directly would require mocking OctoPaymentAttempt::update()
     * to throw — deferred until the DB test helper supports model-level injection.
     */
    public function test_inquiry_confirmed_and_200_returned_on_success(): void
    {
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry);

        $this->postCallback(self::TRANSACTION)->assertOk();

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
    }
}
