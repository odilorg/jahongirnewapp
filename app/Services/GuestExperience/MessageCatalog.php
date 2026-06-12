<?php

declare(strict_types=1);

namespace App\Services\GuestExperience;

use App\Enums\GuestExperienceMessageType;
use App\Models\BookingInquiry;

/**
 * Resolves which experience messages apply to a booking and renders their
 * bodies (Phase 29).
 *
 * Applicability is catalog-driven: a tour_slug must be present in
 * config('tour_experience.experience_messages') to receive any messages.
 * This is intentional — it does NOT depend on tour_products.duration_days
 * (mis-tagged for several multi-day tours). The catalog entry declares the
 * tour's own day_count, used to time day-2+ messages correctly.
 */
class MessageCatalog
{
    /** True if the booking's tour is opted in to experience messages. */
    public function appliesTo(BookingInquiry $inquiry): bool
    {
        return $this->entryFor($inquiry) !== null;
    }

    /** Catalogued day count for the booking's tour, or null if not catalogued. */
    public function dayCount(BookingInquiry $inquiry): ?int
    {
        $entry = $this->entryFor($inquiry);

        return $entry['day_count'] ?? null;
    }

    /**
     * Message types applicable to this booking — catalogued types whose
     * required tour day count fits within the tour's day_count.
     *
     * @return GuestExperienceMessageType[]
     */
    public function typesFor(BookingInquiry $inquiry): array
    {
        $entry = $this->entryFor($inquiry);
        if ($entry === null) {
            return [];
        }

        $dayCount = (int) ($entry['day_count'] ?? 1);
        $defined = array_keys($entry['messages'] ?? []);

        return array_values(array_filter(
            GuestExperienceMessageType::ordered(),
            fn (GuestExperienceMessageType $t) => in_array($t->value, $defined, true)
                && $t->requiresDayCount() <= $dayCount,
        ));
    }

    /**
     * Render the message body for a (booking, type), or null if the tour
     * has no body configured for that type.
     */
    public function render(BookingInquiry $inquiry, GuestExperienceMessageType $type): ?string
    {
        $entry = $this->entryFor($inquiry);
        $template = $entry['messages'][$type->value] ?? null;
        if ($template === null) {
            return null;
        }

        $firstName = $this->firstName($inquiry->customer_name);

        return str_replace('{name}', $firstName, $template);
    }

    /** @return array{day_count:int, messages:array<string,string>}|null */
    private function entryFor(BookingInquiry $inquiry): ?array
    {
        $slug = $inquiry->tour_slug;
        if (! $slug) {
            return null;
        }

        return config("tour_experience.experience_messages.{$slug}");
    }

    private function firstName(?string $fullName): string
    {
        $name = trim((string) $fullName);
        if ($name === '') {
            return 'there';
        }

        return explode(' ', $name)[0];
    }
}
