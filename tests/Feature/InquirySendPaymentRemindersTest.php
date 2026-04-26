<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BookingInquiry;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-04-26 hourly-spam incident:
 * `payment_reminder_sent_at` was missing from $fillable, so $inquiry->update()
 * silently dropped the column and the cron resent the same reminder every hour.
 */
class InquirySendPaymentRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUnpaidInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'            => 'INQ-TEST-' . uniqid(),
            'source'               => 'website',
            'status'               => BookingInquiry::STATUS_AWAITING_PAYMENT,
            'customer_name'        => 'Test Guest',
            'customer_phone'       => '998901234567',
            'customer_email'       => 'guest@example.com',
            'tour_name_snapshot'   => 'Bukhara City Tour',
            'people_adults'        => 2,
            'people_children'      => 0,
            'travel_date'          => now()->addDays(10)->toDateString(),
            'submitted_at'         => now()->subHours(6),
            'price_quoted'         => 100.00,
            'amount_online_usd'    => 100.00,
            'amount_cash_usd'      => 0.00,
            'payment_link'         => 'https://pay.example.com/test',
            'payment_link_sent_at' => now()->subHours(6),
            'created_at'           => now()->subHours(6),
        ], $overrides));
    }

    private function bindWaMock(int $expectedCalls): WhatsAppSender
    {
        $mock = $this->createMock(WhatsAppSender::class);
        $mock->method('normalizePhone')->willReturnCallback(
            fn (?string $p) => $p ? preg_replace('/[^0-9]/', '', $p) : null
        );
        $mock->expects($this->exactly($expectedCalls))
            ->method('send')
            ->willReturn(SendResult::ok('whatsapp'));

        $this->app->instance(WhatsAppSender::class, $mock);

        return $mock;
    }

    public function test_first_run_sends_reminder_and_stamps_payment_reminder_sent_at(): void
    {
        $inquiry = $this->makeUnpaidInquiry();
        $this->bindWaMock(expectedCalls: 1);

        $this->artisan('inquiry:send-payment-reminders')->assertSuccessful();

        $inquiry->refresh();
        $this->assertNotNull(
            $inquiry->payment_reminder_sent_at,
            'payment_reminder_sent_at MUST be persisted after a successful send — '
            . 'a NULL value here means $fillable is missing the column and the cron will resend hourly.'
        );
    }

    public function test_second_run_does_not_resend_to_same_inquiry(): void
    {
        $this->makeUnpaidInquiry();
        $this->bindWaMock(expectedCalls: 1);

        $this->artisan('inquiry:send-payment-reminders')->assertSuccessful();
        $this->artisan('inquiry:send-payment-reminders')->assertSuccessful();
    }

    public function test_run_skips_inquiry_with_link_sent_less_than_4_hours_ago(): void
    {
        $this->makeUnpaidInquiry([
            'payment_link_sent_at' => now()->subHours(2),
            'created_at'           => now()->subHours(2),
        ]);
        $this->bindWaMock(expectedCalls: 0);

        $this->artisan('inquiry:send-payment-reminders')->assertSuccessful();
    }

    public function test_run_skips_inquiry_already_paid(): void
    {
        $this->makeUnpaidInquiry(['paid_at' => now()->subHour()]);
        $this->bindWaMock(expectedCalls: 0);

        $this->artisan('inquiry:send-payment-reminders')->assertSuccessful();
    }
}
