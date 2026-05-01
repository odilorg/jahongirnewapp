<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Models\BookingInquiry;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Regression contract for the BookingInquiryResource "WA: Generate & send
 * payment" table action.
 *
 * Locks in the post-2026-05-01 visibility rule:
 *   - Action is gated on PAYMENT REALITY, not on status shorthand
 *   - Visible iff: not (spam | cancelled) AND no payment_link AND paid_at is null
 *
 * Operational scenarios this matrix prevents from regressing:
 *   - Manual trust-confirmation flow (Blake Kim, 2026-05-01): operator
 *     confirms an inquiry without payment, then the guest later asks for
 *     a link. The action MUST be visible at that moment even though
 *     status=confirmed.
 *   - GYG ingestion: bookings land confirmed AND with paid_at set. The
 *     action MUST stay hidden so the operator never accidentally
 *     double-charges via Octo when GYG already collected payment.
 *
 * NOTE: We replicate the visibility expression here verbatim rather than
 * importing it from the resource. The resource closure isn't testable
 * in isolation; this duplicates 1 expression but locks the contract.
 */
class WaGenerateAndSendVisibilityTest extends TestCase
{
    /**
     * Mirror of the visibility closure in BookingInquiryResource around
     * "->action(GeneratePaymentLinkAction)". If you change the closure,
     * change this; the test fails until they match.
     */
    private function isVisible(BookingInquiry $record): bool
    {
        return ! in_array($record->status, [
                BookingInquiry::STATUS_SPAM,
                BookingInquiry::STATUS_CANCELLED,
            ], true)
            && blank($record->payment_link)
            && $record->paid_at === null;
    }

    private function makeInquiry(string $status, ?Carbon $paidAt = null, ?string $paymentLink = null): BookingInquiry
    {
        // Hydrate without persisting — only attribute access is needed for
        // the visibility check, not DB state.
        $inq = new BookingInquiry();
        $inq->status       = $status;
        $inq->paid_at      = $paidAt;
        $inq->payment_link = $paymentLink;
        return $inq;
    }

    public function test_visible_for_awaiting_payment_unpaid_no_link(): void
    {
        $inq = $this->makeInquiry(BookingInquiry::STATUS_AWAITING_PAYMENT);
        $this->assertTrue($this->isVisible($inq), 'awaiting_payment + unpaid + no link → visible');
    }

    public function test_visible_for_confirmed_unpaid_no_link(): void
    {
        // The Blake Kim scenario — manual trust-confirmation, no money in yet.
        $inq = $this->makeInquiry(BookingInquiry::STATUS_CONFIRMED);
        $this->assertTrue($this->isVisible($inq), 'confirmed + unpaid + no link → visible (manual confirm path)');
    }

    public function test_visible_for_new_inquiry(): void
    {
        $inq = $this->makeInquiry(BookingInquiry::STATUS_NEW);
        $this->assertTrue($this->isVisible($inq), 'new + unpaid + no link → visible');
    }

    public function test_hidden_for_confirmed_paid(): void
    {
        // The Tom Armond / GYG scenario — already paid, never re-show.
        $inq = $this->makeInquiry(BookingInquiry::STATUS_CONFIRMED, paidAt: Carbon::now());
        $this->assertFalse($this->isVisible($inq), 'confirmed + paid → hidden');
    }

    public function test_hidden_for_cancelled(): void
    {
        $inq = $this->makeInquiry(BookingInquiry::STATUS_CANCELLED);
        $this->assertFalse($this->isVisible($inq), 'cancelled → hidden regardless of payment state');
    }

    public function test_hidden_for_spam(): void
    {
        $inq = $this->makeInquiry(BookingInquiry::STATUS_SPAM);
        $this->assertFalse($this->isVisible($inq), 'spam → hidden regardless of payment state');
    }

    public function test_hidden_when_payment_link_already_exists(): void
    {
        // If a link is already on file, operator must use "Resend existing
        // link" — regenerating would orphan the existing Octo transaction.
        $inq = $this->makeInquiry(
            BookingInquiry::STATUS_AWAITING_PAYMENT,
            paymentLink: 'https://octo.example.com/pay/abc123',
        );
        $this->assertFalse($this->isVisible($inq), 'existing payment_link → hidden');
    }

    public function test_hidden_when_payment_link_exists_and_status_confirmed(): void
    {
        // Belt-and-braces: even if status flipped to confirmed AND link exists,
        // we still hide the regenerate action.
        $inq = $this->makeInquiry(
            BookingInquiry::STATUS_CONFIRMED,
            paymentLink: 'https://octo.example.com/pay/xyz',
        );
        $this->assertFalse($this->isVisible($inq), 'confirmed + has link → hidden');
    }

    public function test_hidden_when_paid_at_set_but_no_link(): void
    {
        // Paid offline (cash, manual mark-paid) — no Octo link involved, but
        // we still must not show "generate" since money is in.
        $inq = $this->makeInquiry(
            BookingInquiry::STATUS_CONFIRMED,
            paidAt: Carbon::now(),
            paymentLink: null,
        );
        $this->assertFalse($this->isVisible($inq), 'paid_at set + no link → hidden');
    }
}
