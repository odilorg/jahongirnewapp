<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\Beds24BookingService;
use App\Services\BookingCommandService;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramKeyboardService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class BookingCommandServicePaymentTest extends TestCase
{
    private MockInterface $beds24;
    private BookingCommandService $service;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beds24 = Mockery::mock(Beds24BookingService::class);

        $keyboard    = Mockery::mock(TelegramKeyboardService::class);
        $authService = Mockery::mock(StaffAuthorizationService::class);

        $this->service = new BookingCommandService($this->beds24, $keyboard, $authService);

        // Minimal User stub — only 'name' is accessed in handleRecordPayment
        $this->staff       = new User();
        $this->staff->name = 'John Walker';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── Happy path ─────────────────────────────────────────────────────────────

    public function test_successful_payment_returns_confirmation(): void
    {
        $this->beds24->shouldReceive('writePaymentItem')
            ->once()
            ->with(123456, 100.0, Mockery::type('string'));

        $this->beds24->shouldReceive('getBookingBalance')
            ->once()
            ->with(123456)
            ->andReturn(50.0);

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '123456',
            'payment'    => ['amount' => 100, 'method' => 'cash'],
        ], $this->staff);

        $this->assertStringContainsString('✅ Payment Recorded', $result);
        $this->assertStringContainsString('#123456', $result);
        $this->assertStringContainsString('$100.00', $result);
        $this->assertStringContainsString('Cash', $result);
        $this->assertStringContainsString('John Walker', $result);
    }

    public function test_balance_is_shown_when_returned(): void
    {
        $this->beds24->shouldReceive('writePaymentItem')->once();
        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(25.50);

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '111',
            'payment'    => ['amount' => 75],
        ], $this->staff);

        $this->assertStringContainsString('Balance due', $result);
        $this->assertStringContainsString('$25.50', $result);
    }

    public function test_balance_is_omitted_when_null(): void
    {
        $this->beds24->shouldReceive('writePaymentItem')->once();
        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(null);

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '111',
            'payment'    => ['amount' => 75],
        ], $this->staff);

        $this->assertStringNotContainsString('Balance', $result);
    }

    public function test_method_line_is_omitted_when_no_method_given(): void
    {
        $this->beds24->shouldReceive('writePaymentItem')->once();
        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(null);

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '111',
            'payment'    => ['amount' => 75, 'method' => null],
        ], $this->staff);

        $this->assertStringNotContainsString('Method:', $result);
    }

    // ── Audit marker ───────────────────────────────────────────────────────────

    public function test_audit_marker_is_embedded_in_description(): void
    {
        $capturedDescription = null;

        $this->beds24->shouldReceive('writePaymentItem')
            ->once()
            ->withArgs(function (int $id, float $amount, string $desc) use (&$capturedDescription) {
                $capturedDescription = $desc;
                return true;
            });

        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(null);

        $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '999',
            'payment'    => ['amount' => 200, 'method' => 'card'],
        ], $this->staff);

        $this->assertNotNull($capturedDescription);
        $this->assertStringContainsString('BOT-PMT', $capturedDescription);
        $this->assertStringContainsString('999', $capturedDescription);
        $this->assertStringContainsString('card', $capturedDescription);
        $this->assertStringContainsString('Card payment', $capturedDescription);
    }

    public function test_method_label_in_description_for_cash(): void
    {
        $capturedDescription = null;

        $this->beds24->shouldReceive('writePaymentItem')
            ->once()
            ->withArgs(function (int $id, float $amount, string $desc) use (&$capturedDescription) {
                $capturedDescription = $desc;
                return true;
            });
        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(null);

        $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '1',
            'payment'    => ['amount' => 50, 'method' => 'cash'],
        ], $this->staff);

        $this->assertStringStartsWith('Cash payment', $capturedDescription);
    }

    public function test_unspecified_label_when_no_method(): void
    {
        $capturedDescription = null;

        $this->beds24->shouldReceive('writePaymentItem')
            ->once()
            ->withArgs(function (int $id, float $amount, string $desc) use (&$capturedDescription) {
                $capturedDescription = $desc;
                return true;
            });
        $this->beds24->shouldReceive('getBookingBalance')->once()->andReturn(null);

        $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '1',
            'payment'    => ['amount' => 50],
        ], $this->staff);

        $this->assertStringStartsWith('Unspecified payment', $capturedDescription);
    }

    // ── Validation failures ────────────────────────────────────────────────────

    public function test_validation_error_is_returned_without_calling_beds24(): void
    {
        $this->beds24->shouldNotReceive('writePaymentItem');
        $this->beds24->shouldNotReceive('getBookingBalance');

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '',
            'payment'    => ['amount' => 100],
        ], $this->staff);

        $this->assertStringContainsString('booking ID', $result);
    }

    public function test_zero_amount_rejected_without_calling_beds24(): void
    {
        $this->beds24->shouldNotReceive('writePaymentItem');
        $this->beds24->shouldNotReceive('getBookingBalance');

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '123',
            'payment'    => ['amount' => 0],
        ], $this->staff);

        $this->assertStringContainsString('greater than zero', $result);
    }

    // ── Beds24 failure ─────────────────────────────────────────────────────────

    public function test_beds24_failure_returns_error_message(): void
    {
        $this->beds24->shouldReceive('writePaymentItem')
            ->once()
            ->andThrow(new \RuntimeException('HTTP 500 Internal Server Error'));

        $this->beds24->shouldNotReceive('getBookingBalance');

        $result = $this->service->handle([
            'intent'     => 'record_payment',
            'booking_id' => '123456',
            'payment'    => ['amount' => 100],
        ], $this->staff);

        $this->assertStringContainsString('❌', $result);
        $this->assertStringContainsString('#123456', $result);
        $this->assertStringContainsString('manually in Beds24', $result);
    }
}
