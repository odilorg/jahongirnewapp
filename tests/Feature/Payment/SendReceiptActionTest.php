<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Payment\SendReceiptAction;
use App\Mail\BookingPaymentReceiptMail;
use App\Models\BookingInquiry;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SendReceiptAction contract tests.
 *
 * Ten scenarios per spec:
 *  1. Full payment → email + WhatsApp sent, receipt_sent_at stamped.
 *  2. Partial payment → cash-due wording in WA message, email view has partial flag.
 *  3. No phone → email only, receipt_sent_at stamped.
 *  4. No email → WhatsApp only, receipt_sent_at stamped.
 *  5. No contact at all → logs warning, receipt_sent_at NOT stamped, failure result.
 *  6. Not confirmed / not paid → skipped, no channels fired, failure result.
 *  7. receipt_sent_at already set + no force → skipped (success with skipped=true).
 *  8. receipt_sent_at already set + force=true → sends again, does NOT clear sent_at.
 *  9. Email exception → does not propagate, action returns gracefully.
 * 10. receipt_sent_at only stamped after at least one channel succeeds.
 */
class SendReceiptActionTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeConfirmedPaidInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => 'INQ-TEST-' . uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 'guest@example.com',
            'tour_name_snapshot' => 'Bukhara City Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'travel_date'        => now()->addDays(10)->toDateString(),
            'submitted_at'       => now(),
            'price_quoted'       => 100.00,
            'amount_online_usd'  => 100.00,
            'amount_cash_usd'    => 0.00,
            'paid_at'            => now(),
        ], $overrides));
    }

    private function mockWaSuccess(): WhatsAppSender
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null);
        $mock->method('send')->willReturn(SendResult::ok('whatsapp'));
        return $mock;
    }

    private function mockWaFail(): WhatsAppSender
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null);
        $mock->method('send')->willReturn(SendResult::fail('whatsapp', 'wa-api error'));
        return $mock;
    }

    private function action(WhatsAppSender $wa = null): SendReceiptAction
    {
        return new SendReceiptAction(
            $wa ?? $this->mockWaSuccess(),
        );
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /** 1. Full payment → email + WA sent, receipt_sent_at stamped. */
    public function test_full_payment_sends_both_channels_and_stamps(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry();

        $wa = $this->mockWaSuccess();
        $wa->expects($this->once())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertTrue($result->success);
        $this->assertSame('both', $result->payload['channels']);
        Mail::assertSent(BookingPaymentReceiptMail::class, fn ($m) => $m->hasTo('guest@example.com'));
        $this->assertNotNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 2. Partial payment → WA message contains remaining-cash wording. */
    public function test_partial_payment_includes_cash_due_in_wa_message(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry([
            'price_quoted'      => 150.00,
            'amount_online_usd' => 100.00,
            'amount_cash_usd'   => 50.00,
        ]);

        $waMessages = [];
        $wa = $this->createMock(WhatsAppSender::class);
        $wa->method('normalizePhone')->willReturn('998901234567');
        $wa->method('send')->willReturnCallback(function (string $phone, string $msg) use (&$waMessages) {
            $waMessages[] = $msg;
            return SendResult::ok('whatsapp');
        });

        $result = $this->action($wa)->execute($inquiry);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($waMessages);
        $this->assertStringContainsString('50.00', $waMessages[0]);
        $this->assertStringContainsString('cash', strtolower($waMessages[0]));
    }

    /** 3. No phone → email only sent. */
    public function test_no_phone_sends_email_only(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry(['customer_phone' => 'not-provided']);

        $wa = $this->createMock(WhatsAppSender::class);
        $wa->method('normalizePhone')->willReturn(null);
        $wa->expects($this->never())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertTrue($result->success);
        $this->assertSame('email', $result->payload['channels']);
        Mail::assertSent(BookingPaymentReceiptMail::class);
        $this->assertNotNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 4. No email → WhatsApp only sent. */
    public function test_no_email_sends_whatsapp_only(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry(['customer_email' => '']);

        $wa = $this->mockWaSuccess();
        $result = $this->action($wa)->execute($inquiry);

        $this->assertTrue($result->success);
        $this->assertSame('whatsapp', $result->payload['channels']);
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
        $this->assertNotNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 5. No contact at all → warning logged, not stamped, failure result. */
    public function test_no_contact_logs_warning_and_returns_failure(): void
    {
        Mail::fake();
        Log::shouldReceive('warning')
            ->with(\Mockery::pattern('/no deliverable contact/i'), \Mockery::any())
            ->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $inquiry = $this->makeConfirmedPaidInquiry([
            'customer_email' => '',
            'customer_phone' => 'not-provided',
        ]);

        $wa = $this->createMock(WhatsAppSender::class);
        $wa->method('normalizePhone')->willReturn(null);
        $wa->expects($this->never())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertFalse($result->success);
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
        $this->assertNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 6a. Not confirmed → skipped, failure result. */
    public function test_not_confirmed_returns_failure(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry([
            'status' => BookingInquiry::STATUS_AWAITING_PAYMENT,
        ]);

        $wa = $this->createMock(WhatsAppSender::class);
        $wa->expects($this->never())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertFalse($result->success);
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
        $this->assertNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 6b. Not paid (paid_at null) → skipped, failure result. */
    public function test_not_paid_returns_failure(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry(['paid_at' => null]);

        $wa = $this->createMock(WhatsAppSender::class);
        $wa->expects($this->never())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertFalse($result->success);
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
    }

    /** 7. Already sent + no force → skipped (success with skipped=true). */
    public function test_already_sent_without_force_returns_skipped(): void
    {
        Mail::fake();
        $inquiry = $this->makeConfirmedPaidInquiry(['receipt_sent_at' => now()->subHour()]);

        $wa = $this->createMock(WhatsAppSender::class);
        $wa->expects($this->never())->method('send');

        $result = $this->action($wa)->execute($inquiry);

        $this->assertTrue($result->success);
        $this->assertTrue($result->payload['skipped'] ?? false);
        Mail::assertNotSent(BookingPaymentReceiptMail::class);
    }

    /** 8. Already sent + force=true → sends again, does NOT clear receipt_sent_at. */
    public function test_force_resend_sends_again_and_does_not_clear_sent_at(): void
    {
        Mail::fake();
        $originalSentAt = now()->subHour();
        $inquiry = $this->makeConfirmedPaidInquiry(['receipt_sent_at' => $originalSentAt]);

        $wa = $this->mockWaSuccess();
        $result = $this->action($wa)->execute($inquiry, force: true);

        $this->assertTrue($result->success);
        Mail::assertSent(BookingPaymentReceiptMail::class);
        // receipt_sent_at must NOT be null; its exact value may be updated (re-stamp on resend is acceptable)
        $this->assertNotNull($inquiry->fresh()->receipt_sent_at);
    }

    /** 9. Email exception does not propagate, action returns gracefully. */
    public function test_email_exception_does_not_propagate(): void
    {
        // Bind an anon object as mail.manager so Mail facade throws on to()
        $fakeBadMailer = new class {
            public function to(mixed $users): static { throw new \RuntimeException('SMTP down'); }
            public function mailer(?string $name = null): static { return $this; }
        };
        $this->app->instance('mail.manager', $fakeBadMailer);

        $inquiry = $this->makeConfirmedPaidInquiry(['customer_phone' => 'not-provided']);
        $wa = $this->createMock(WhatsAppSender::class);
        $wa->method('normalizePhone')->willReturn(null);

        $action = new SendReceiptAction($wa);
        $result = $action->execute($inquiry);

        // Must not throw; result is failure (both channels failed/unavailable)
        $this->assertFalse($result->success);
    }

    /** 10. receipt_sent_at only stamped after at least one channel succeeds. */
    public function test_receipt_sent_at_not_stamped_when_all_channels_fail(): void
    {
        // Force email to fail via bound anon object as mail.manager
        $fakeBadMailer = new class {
            public function to(mixed $users): static { throw new \RuntimeException('mail down'); }
            public function mailer(?string $name = null): static { return $this; }
        };
        $this->app->instance('mail.manager', $fakeBadMailer);

        $inquiry = $this->makeConfirmedPaidInquiry();
        $wa = $this->mockWaFail();

        $action = new SendReceiptAction($wa);
        $result = $action->execute($inquiry);

        $this->assertFalse($result->success);
        $this->assertNull($inquiry->fresh()->receipt_sent_at);
    }
}
