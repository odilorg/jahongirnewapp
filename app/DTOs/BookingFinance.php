<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents an optional quoted total provided by an operator at booking time.
 *
 * Construction semantics:
 *   - 'price' key absent from $parsed  → fromParsed() returns null  (not mentioned)
 *   - 'price' key present, value null  → fromParsed() returns null  (parser found no price)
 *   - 'price' key present, value <= 0  → object constructed, validationError() returns an error
 *   - 'price' key present, valid value → object constructed, validationError() returns null
 *
 * A zero or negative amount that was explicitly provided is a validation error, not silent null.
 * This avoids silently creating a booking with no charge when the operator typed e.g. "$0".
 */
readonly class BookingFinance
{
    private const ALLOWED_CURRENCIES = ['USD'];
    private const MAX_AMOUNT         = 99_999.0;

    public function __construct(
        public float  $quotedTotal,
        public string $currency = 'USD',
    ) {}

    /**
     * Build from a raw parsed intent array.
     *
     * Returns null when 'price' is absent or null (no finance was provided).
     * Returns an object when 'price' is present — even if the value is invalid,
     * so that validationError() can surface a clear operator-facing message.
     */
    public static function fromParsed(array $parsed): ?self
    {
        if (!array_key_exists('price', $parsed)) {
            return null;
        }

        if ($parsed['price'] === null) {
            return null;
        }

        return new self((float) $parsed['price']);
    }

    /**
     * Returns a user-facing validation error string, or null when the finance data is valid.
     */
    public function validationError(): ?string
    {
        if ($this->quotedTotal <= 0) {
            return 'Quoted total must be greater than zero. Please provide a valid amount.';
        }

        if ($this->quotedTotal > self::MAX_AMOUNT) {
            return "Quoted total of \${$this->quotedTotal} seems unreasonably high. Please verify the amount.";
        }

        if (!in_array($this->currency, self::ALLOWED_CURRENCIES, true)) {
            return "Currency '{$this->currency}' is not supported. Use USD.";
        }

        return null;
    }
}
