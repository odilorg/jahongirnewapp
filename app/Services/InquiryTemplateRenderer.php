<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use Illuminate\Support\Str;

/**
 * Render WhatsApp message templates for a booking inquiry.
 *
 * Templates live in config/inquiry_templates.php as plain strings with
 * {placeholder} tokens. The renderer builds a safe default context from the
 * inquiry record (customer name, tour, pax, date) and merges caller-supplied
 * extras on top (for action-specific tokens like {price} and {link}).
 *
 * Unknown placeholders are left in place verbatim so the operator sees them
 * in the preview and is reminded to fill them in manually — safer than
 * silently rendering "blank".
 */
class InquiryTemplateRenderer
{
    /**
     * @param  array<string, string|int|float|null>  $extras
     */
    public function render(string $templateKey, BookingInquiry $inquiry, array $extras = []): string
    {
        $template = (string) config("inquiry_templates.{$templateKey}", '');

        if ($template === '') {
            return '';
        }

        $context = $this->buildContext($inquiry, $extras);

        return str_replace(
            array_map(fn (string $k) => '{' . $k . '}', array_keys($context)),
            array_values($context),
            $template,
        );
    }

    /**
     * @param  array<string, string|int|float|null>  $extras
     * @return array<string, string>
     */
    private function buildContext(BookingInquiry $inquiry, array $extras): array
    {
        $base = [
            'name' => $this->firstName((string) $inquiry->customer_name),
            'tour' => (string) $inquiry->tour_name_snapshot,
            'pax'  => $this->formatPax($inquiry->people_adults, $inquiry->people_children),
            'date' => $this->formatDate($inquiry),
        ];

        // Caller extras override base — allows actions to re-inject date/pax
        // if they want a different format, though we avoid that in practice.
        $merged = $base + array_map(fn ($v) => (string) $v, $extras);

        // Order extras first so they're resolved alongside base tokens.
        return array_merge($base, array_map(fn ($v) => (string) $v, $extras));
    }

    private function firstName(string $fullName): string
    {
        $trimmed = trim($fullName);

        if ($trimmed === '') {
            return 'there';
        }

        // Take the first whitespace-delimited word so "Odil Jurayev" → "Odil".
        return (string) Str::before($trimmed, ' ') ?: $trimmed;
    }

    /**
     * Human-readable pax: "1 adult", "2 adults", "2 adults, 1 child",
     * "3 adults, 2 children". Avoids robotic "2+1" in customer messages.
     */
    private function formatPax(int $adults, int $children): string
    {
        $parts = [];

        if ($adults > 0) {
            $parts[] = $adults . ' ' . ($adults === 1 ? 'adult' : 'adults');
        }

        if ($children > 0) {
            $parts[] = $children . ' ' . ($children === 1 ? 'child' : 'children');
        }

        return $parts === [] ? 'your group' : implode(', ', $parts);
    }

    private function formatDate(BookingInquiry $inquiry): string
    {
        if (! $inquiry->travel_date) {
            return 'your selected date';
        }

        return $inquiry->travel_date->format('F j, Y');
    }
}
