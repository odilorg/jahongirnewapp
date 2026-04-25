<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use App\Models\OctoPaymentAttempt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 4 contract: the callback endpoint short-circuits on the attempt's
 * own terminal status BEFORE resolving the inquiry.
 *
 * Three terminal states (superseded / paid / failed) each trigger an
 * early 200 so Octo stops retrying. In every case the inquiry is NOT
 * touched — no status change, no GuestPayment row, no notification.
 *
 * Why superseded matters: Octo keeps a link alive until its own TTL.
 * An operator may regenerate a new link (Phase 3) while the old one is
 * still live; both may fire. The superseded guard silently discards the
 * stale callback so only the active link can confirm the inquiry.
 */
class OctoCallbackPhase4AttemptStatusGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const TXN_SUPERSEDED = 'inquiry_101_P4_superseded';
    private const TXN_PAID       = 'inquiry_101_P4_paid';
    private const TXN_FAILED     = 'inquiry_101_P4_failed';

    private function postCallback(string $txn, string $status = 'success', int $sum = 7_000_000): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('octo.callback'), [
            'shop_transaction_id' => $txn,
            'status'              => $status,
            'total_sum'           => $sum,
        ]);
    }

    private function makeInquiry(): BookingInquiry
    {
        return BookingInquiry::create([
            'reference'          => 'INQ-P4-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'customer_name'      => 'Phase Four Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'p4@test.com',
            'tour_name_snapshot' => 'Phase 4 Tour',
            'people_adults'      => 1,
            'people_children'    => 0,
            'submitted_at'       => now(),
            'price_quoted'       => 100.00,
            'amount_online_usd'  => 100.00,
            'amount_cash_usd'    => 0.00,
        ]);
    }

    private function makeAttempt(BookingInquiry $inquiry, string $txn, string $status): OctoPaymentAttempt
    {
        return OctoPaymentAttempt::create([
            'inquiry_id'              => $inquiry->id,
            'transaction_id'          => $txn,
            'amount_online_usd'       => 100.00,
            'price_quoted_at_attempt' => 100.00,
            'exchange_rate_used'      => 12500.00,
            'uzs_amount'              => 1_250_000,
            'status'                  => $status,
        ]);
    }

    /**
     * Superseded attempt → 200 with note, inquiry unchanged, no payment row.
     *
     * Scenario: operator regenerated the link (Phase 3 superseded the old
     * attempt). Old Octo link fires before its TTL expires.
     */
    public function test_superseded_attempt_returns_200_and_does_not_touch_inquiry(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry, self::TXN_SUPERSEDED, OctoPaymentAttempt::STATUS_SUPERSEDED);

        $response = $this->postCallback(self::TXN_SUPERSEDED)->assertOk();
        $this->assertEquals('attempt_terminal_' . OctoPaymentAttempt::STATUS_SUPERSEDED, $response->json('note'));

        $inquiry->refresh();
        $attempt->refresh();

        // Inquiry not confirmed — guard fired before inquiry processing.
        $this->assertEquals(BookingInquiry::STATUS_AWAITING_PAYMENT, $inquiry->status);
        $this->assertNull($inquiry->paid_at);
        $this->assertCount(0, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());

        // Attempt status unchanged — superseded stays superseded.
        $this->assertEquals(OctoPaymentAttempt::STATUS_SUPERSEDED, $attempt->status);
    }

    /**
     * Already-paid attempt → 200, inquiry untouched, no duplicate payment.
     *
     * Scenario: Octo fires the callback a second time because it didn't
     * receive our 200 on the first delivery. Without the guard this would
     * create a second GuestPayment row.
     */
    public function test_paid_attempt_returns_200_and_does_not_create_duplicate_payment(): void
    {
        $inquiry = $this->makeInquiry([]);
        // Simulate inquiry already confirmed + paid.
        $inquiry->update([
            'status'  => BookingInquiry::STATUS_CONFIRMED,
            'paid_at' => now(),
        ]);
        $attempt = $this->makeAttempt($inquiry, self::TXN_PAID, OctoPaymentAttempt::STATUS_PAID);

        $response = $this->postCallback(self::TXN_PAID)->assertOk();
        $this->assertEquals('attempt_terminal_' . OctoPaymentAttempt::STATUS_PAID, $response->json('note'));

        $inquiry->refresh();
        // Inquiry status not mutated by the duplicate callback.
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertCount(0, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
    }

    /**
     * Already-failed attempt → 200, inquiry untouched.
     *
     * Scenario: Octo fires a second failure callback. Without the guard
     * the internal_notes audit trail would gain a duplicate entry.
     */
    public function test_failed_attempt_returns_200_and_does_not_modify_inquiry(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry, self::TXN_FAILED, OctoPaymentAttempt::STATUS_FAILED);
        $originalNotes = $inquiry->internal_notes;

        $response = $this->postCallback(self::TXN_FAILED, 'failed')->assertOk();
        $this->assertEquals('attempt_terminal_' . OctoPaymentAttempt::STATUS_FAILED, $response->json('note'));

        $inquiry->refresh();
        // No new audit note appended — guard fired before inquiry processing.
        $this->assertEquals(BookingInquiry::STATUS_AWAITING_PAYMENT, $inquiry->status);
        $this->assertEquals($originalNotes, $inquiry->internal_notes);
    }

    /**
     * Active attempt is NOT blocked — guard only fires for terminal states.
     * Ensures the guard doesn't over-catch live payment links.
     */
    public function test_active_attempt_passes_through_guard(): void
    {
        $inquiry = $this->makeInquiry();
        $attempt = $this->makeAttempt($inquiry, 'inquiry_101_P4_active', OctoPaymentAttempt::STATUS_ACTIVE);

        $response = $this->postCallback('inquiry_101_P4_active')->assertOk();
        // No 'note' key means the guard did not fire.
        $this->assertNull($response->json('note'));

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertCount(1, GuestPayment::where('booking_inquiry_id', $inquiry->id)->get());
    }

    /**
     * Guard fires regardless of what Octo's status field says.
     * Even a 'success' callback on a superseded attempt must be discarded —
     * the attempt's stored status is authoritative, not the incoming payload.
     */
    public function test_superseded_attempt_blocked_even_with_success_status(): void
    {
        $inquiry = $this->makeInquiry();
        $this->makeAttempt($inquiry, self::TXN_SUPERSEDED . '_b', OctoPaymentAttempt::STATUS_SUPERSEDED);

        $response = $this->postCallback(self::TXN_SUPERSEDED . '_b', 'success')->assertOk();
        $this->assertStringStartsWith('attempt_terminal_', $response->json('note'));

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_AWAITING_PAYMENT, $inquiry->status);
    }
}
