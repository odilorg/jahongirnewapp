<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Inquiry\ConfirmBookingAction;
use App\Models\BookingInquiry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Contract tests for ConfirmBookingAction.
 *
 * Status / paid_at independence is the load-bearing invariant — confirming
 * never touches paid_at or payment fields.
 */
class ConfirmBookingActionTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'guest@example.com',
            'tour_name_snapshot' => 'Bukhara City Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => now()->addDays(10)->toDateString(),
            'submitted_at'       => now(),
        ], $overrides));
    }

    private function action(): ConfirmBookingAction
    {
        return new ConfirmBookingAction();
    }

    public function test_new_inquiry_is_promoted_to_confirmed_with_audit_trail(): void
    {
        $inquiry = $this->makeInquiry();

        $this->action()->execute($inquiry, 'VIP repeat client', 'manual');

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertNotNull($inquiry->confirmed_at);
        $this->assertEquals('manual', $inquiry->confirmation_source);
        $this->assertNull($inquiry->paid_at, 'paid_at MUST stay null — confirm is operational, not financial');
        $this->assertStringContainsString('Confirmed without payment', (string) $inquiry->internal_notes);
        $this->assertStringContainsString('VIP repeat client', (string) $inquiry->internal_notes);
        $this->assertStringContainsString('source=manual', (string) $inquiry->internal_notes);
    }

    public function test_awaiting_payment_can_be_promoted_to_confirmed_for_offline_paid_case(): void
    {
        // Real-world: payment link sent, then client paid offline. Operator
        // confirms manually without going through the Octo flow.
        $inquiry = $this->makeInquiry([
            'status'               => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'payment_link'         => 'https://pay2.octo.uz/pay/abc',
            'payment_link_sent_at' => now()->subHours(2),
        ]);

        $this->action()->execute($inquiry, 'Client paid in cash at office', 'offline');

        $inquiry->refresh();
        $this->assertEquals(BookingInquiry::STATUS_CONFIRMED, $inquiry->status);
        $this->assertEquals('offline', $inquiry->confirmation_source);
        $this->assertNotNull($inquiry->confirmed_at);
        $this->assertNull($inquiry->paid_at, 'paid_at must stay null — confirm is not a payment event');
        $this->assertEquals('https://pay2.octo.uz/pay/abc', $inquiry->payment_link, 'payment_link must be untouched');
    }

    public function test_cancelled_inquiry_cannot_be_confirmed(): void
    {
        $inquiry = $this->makeInquiry([
            'status'       => BookingInquiry::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->action()->execute($inquiry, 'irrelevant', 'manual');
    }

    public function test_confirm_requires_travel_date_and_at_least_one_adult(): void
    {
        $missingDate = $this->makeInquiry(['travel_date' => null]);
        try {
            $this->action()->execute($missingDate, 'reason', 'manual');
            $this->fail('Expected ValidationException for missing travel_date');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('travel_date', $e->errors());
        }

        $zeroAdults = $this->makeInquiry(['people_adults' => 0]);
        try {
            $this->action()->execute($zeroAdults, 'reason', 'manual');
            $this->fail('Expected ValidationException for people_adults=0');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('people_adults', $e->errors());
        }
    }

    public function test_already_confirmed_is_idempotent_safe_throw(): void
    {
        $inquiry = $this->makeInquiry([
            'status'       => BookingInquiry::STATUS_CONFIRMED,
            'confirmed_at' => now()->subDay(),
        ]);

        $this->expectException(ValidationException::class);
        $this->action()->execute($inquiry, 'reason', 'manual');
    }
}
