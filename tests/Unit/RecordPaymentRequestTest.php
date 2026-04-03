<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\RecordPaymentRequest;
use PHPUnit\Framework\TestCase;

class RecordPaymentRequestTest extends TestCase
{
    // ── fromParsed normalization ───────────────────────────────────────────────

    public function test_strips_leading_hash_from_booking_id(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '#123456', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertSame('123456', $req->bookingId);
    }

    public function test_trims_whitespace_from_booking_id(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '  123456  ', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertSame('123456', $req->bookingId);
    }

    public function test_lowercases_method(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 50, 'method' => 'CASH']],
            'Staff'
        );

        $this->assertSame('cash', $req->method);
    }

    public function test_method_is_null_when_absent(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 50]],
            'Staff'
        );

        $this->assertNull($req->method);
    }

    public function test_method_is_null_when_empty_string(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 50, 'method' => '']],
            'Staff'
        );

        $this->assertNull($req->method);
    }

    public function test_amount_defaults_to_zero_when_absent(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => []],
            'Staff'
        );

        $this->assertSame(0.0, $req->amount);
    }

    public function test_amount_cast_to_float(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 200]],
            'Staff'
        );

        $this->assertSame(200.0, $req->amount);
    }

    public function test_currency_defaults_to_usd(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 50]],
            'Staff'
        );

        $this->assertSame('USD', $req->currency);
    }

    public function test_recorded_by_is_staff_name(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 50]],
            'John Walker'
        );

        $this->assertSame('John Walker', $req->recordedBy);
    }

    public function test_handles_missing_payment_key_gracefully(): void
    {
        $req = RecordPaymentRequest::fromParsed(['booking_id' => '123'], 'Staff');

        $this->assertSame(0.0, $req->amount);
        $this->assertNull($req->method);
    }

    // ── validationError ───────────────────────────────────────────────────────

    public function test_valid_request_passes(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123456', 'payment' => ['amount' => 100, 'method' => 'cash']],
            'Staff'
        );

        $this->assertNull($req->validationError());
    }

    public function test_rejects_empty_booking_id(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertStringContainsString('booking ID', $req->validationError());
    }

    public function test_rejects_missing_booking_id(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertStringContainsString('booking ID', $req->validationError());
    }

    public function test_rejects_hash_only_booking_id(): void
    {
        // '#' stripped → empty string
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '#', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertStringContainsString('booking ID', $req->validationError());
    }

    public function test_rejects_non_numeric_booking_id(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => 'ABC123', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $error = $req->validationError();
        $this->assertNotNull($error);
        $this->assertStringContainsString('number', $error);
    }

    public function test_rejects_zero_amount(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 0]],
            'Staff'
        );

        $this->assertStringContainsString('greater than zero', $req->validationError());
    }

    public function test_rejects_negative_amount(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => -10]],
            'Staff'
        );

        $this->assertNotNull($req->validationError());
    }

    public function test_rejects_amount_exceeding_max(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 100_000]],
            'Staff'
        );

        $this->assertNotNull($req->validationError());
    }

    public function test_rejects_unknown_payment_method(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 100, 'method' => 'crypto']],
            'Staff'
        );

        $error = $req->validationError();
        $this->assertNotNull($error);
        $this->assertStringContainsString('crypto', $error);
    }

    public function test_accepts_null_method(): void
    {
        $req = RecordPaymentRequest::fromParsed(
            ['booking_id' => '123', 'payment' => ['amount' => 100]],
            'Staff'
        );

        $this->assertNull($req->validationError());
    }

    public function test_accepts_all_valid_methods(): void
    {
        foreach (['cash', 'card', 'transfer'] as $method) {
            $req = RecordPaymentRequest::fromParsed(
                ['booking_id' => '123', 'payment' => ['amount' => 100, 'method' => $method]],
                'Staff'
            );

            $this->assertNull($req->validationError(), "Method '{$method}' should be valid");
        }
    }
}
