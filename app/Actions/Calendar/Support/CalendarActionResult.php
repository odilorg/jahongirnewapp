<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Support;

/**
 * Result envelope returned by every Calendar Action.
 *
 * Framework-agnostic by design: no Filament, no Blade, no Notification.
 * The page layer maps this result into Filament notifications, modal
 * close, or state refresh.
 *
 * See docs/architecture/PRINCIPLES.md #11 — Actions own business outcome,
 * presentation layer owns its own UX translation.
 */
final class CalendarActionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {}

    public static function success(?string $message = null, array $payload = []): self
    {
        return new self(true, $message, $payload);
    }

    public static function failure(string $message, array $payload = []): self
    {
        return new self(false, $message, $payload);
    }
}
