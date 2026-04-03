<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class RecordPaymentRequest
{
    private const ALLOWED_METHODS  = ['cash', 'card', 'transfer'];
    private const MAX_AMOUNT       = 99_999.0;

    public function __construct(
        public string  $bookingId,
        public float   $amount,
        public string  $currency   = 'USD',
        public ?string $method     = null,
        public string  $recordedBy = '',
    ) {}

    /**
     * Build from a raw DeepSeek-parsed intent array + the acting staff member's name.
     *
     * bookingId:  trimmed and stripped of leading '#'
     * amount:     cast to float from payment.amount (0 if absent)
     * method:     lowercased + trimmed; null if empty or absent
     * currency:   defaults to 'USD'
     * recordedBy: staff name passed from the job
     */
    public static function fromParsed(array $parsed, string $staffName): self
    {
        $rawId = trim($parsed['booking_id'] ?? '');
        $bookingId = ltrim($rawId, '#');

        $payment = $parsed['payment'] ?? [];

        $amount   = (float) ($payment['amount'] ?? 0);
        $currency = trim($payment['currency'] ?? 'USD') ?: 'USD';

        $rawMethod = strtolower(trim($payment['method'] ?? ''));
        $method    = $rawMethod !== '' ? $rawMethod : null;

        return new self(
            bookingId:  $bookingId,
            amount:     $amount,
            currency:   $currency,
            method:     $method,
            recordedBy: $staffName,
        );
    }

    /**
     * Returns a user-facing validation error string, or null when the request is valid.
     */
    public function validationError(): ?string
    {
        if ($this->bookingId === '') {
            return 'Please provide a booking ID. Example: record payment 100 for booking 123456';
        }

        if (!is_numeric($this->bookingId)) {
            return 'Booking ID must be a number (e.g. 123456). Got: "' . $this->bookingId . '"';
        }

        if ((int) $this->bookingId <= 0) {
            return 'Booking ID must be a positive number.';
        }

        if ($this->amount <= 0) {
            return 'Payment amount must be greater than zero.';
        }

        if ($this->amount > self::MAX_AMOUNT) {
            return "Payment amount \${$this->amount} seems unreasonably large. Please verify.";
        }

        if ($this->method !== null && !in_array($this->method, self::ALLOWED_METHODS, true)) {
            $allowed = implode(', ', self::ALLOWED_METHODS);
            return "Unknown payment method \"{$this->method}\". Allowed: {$allowed}.";
        }

        return null;
    }
}
