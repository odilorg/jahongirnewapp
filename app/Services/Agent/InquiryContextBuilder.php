<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\BookingInquiry;
use App\Models\OctoPaymentAttempt;
use App\Models\TourPriceTier;
use App\Models\TourProduct;

/**
 * Read-only context assembler for the tour-agent (Phase 0, draft-only).
 *
 * Produces the structured snapshot the headless agent reads BEFORE drafting
 * a reply or proposing an action. It is deliberately a *Builder* (Principle:
 * "display-only data preparation → *Builder service") and is strictly
 * read-only: no writes, no external HTTP, no messaging. The live guest
 * conversation (WhatsApp inbound) is layered on by the runner via the
 * WhatsApp MCP at draft time — this class only knows what the CRM database
 * knows, so it stays pure and side-effect-free.
 *
 * The quote is computed by reusing TourProduct::priceFor() (single source of
 * truth for pricing). Party size = adults + children, matching the locked v1
 * rule that children count the same as adults for quoting.
 */
class InquiryContextBuilder
{
    /**
     * Eager-load set required for a complete, N+1-free snapshot.
     * Pass to BookingInquiry::with(...) before calling build().
     */
    public const EAGER_LOAD = [
        'tourProduct.priceTiers',
        'tourProduct.directions',
        'tourProductDirection',
        'driver',
        'guide',
        'assignedToUser',
        'activePaymentAttempt',
    ];

    /** @return array<string,mixed> */
    public function build(BookingInquiry $inquiry): array
    {
        $partySize = (int) $inquiry->people_adults + (int) $inquiry->people_children;

        return [
            'meta' => [
                'id' => $inquiry->id,
                'reference' => $inquiry->reference,
                'source' => $inquiry->source,
                'source_label' => BookingInquiry::SOURCE_LABELS[$inquiry->source] ?? $inquiry->source,
                'is_ota' => in_array($inquiry->source, BookingInquiry::OTA_SOURCES, true),
                'external_reference' => $inquiry->external_reference,
                'created_at' => $inquiry->created_at?->toIso8601String(),
                'submitted_at' => $inquiry->submitted_at?->toIso8601String(),
            ],
            'guest' => [
                'name' => $inquiry->customer_name,
                'email' => $inquiry->customer_email,
                'phone' => $inquiry->customer_phone,
                'phone_digits' => BookingInquiry::normalizePhone($inquiry->customer_phone),
                'country' => $inquiry->customer_country,
                'preferred_contact' => $inquiry->preferred_contact,
            ],
            'request' => [
                'tour_slug' => $inquiry->tour_slug,
                'tour_name_snapshot' => $inquiry->tour_name_snapshot,
                'tour_type' => $inquiry->tour_type,
                'people_adults' => (int) $inquiry->people_adults,
                'people_children' => (int) $inquiry->people_children,
                'party_size' => $partySize,
                'travel_date' => $inquiry->travel_date?->toDateString(),
                'flexible_dates' => (bool) $inquiry->flexible_dates,
                'guest_message' => $inquiry->message,
                'page_url' => $inquiry->page_url,
            ],
            'tour_product' => $this->tourProductSection($inquiry->tourProduct),
            'quote' => $this->quoteSection($inquiry, $partySize),
            'status' => [
                'commercial' => $inquiry->status,
                'prep' => $inquiry->prep_status,
                'is_dispatchable' => $inquiry->isDispatchable(),
                'is_paid' => $inquiry->paid_at !== null,
                'has_payment_link' => ! empty($inquiry->payment_link),
                'flags' => [
                    'dietary' => (bool) $inquiry->has_dietary_flag,
                    'accessibility' => (bool) $inquiry->has_accessibility_flag,
                    'language' => (bool) $inquiry->has_language_flag,
                    'occasion' => (bool) $inquiry->has_occasion_flag,
                ],
                'experience_messages_opted_out' => (bool) $inquiry->experience_messages_opted_out,
            ],
            'money' => [
                'currency' => $inquiry->currency,
                'price_quoted' => $this->toFloat($inquiry->price_quoted),
                'total_received' => $inquiry->totalReceived(),
                'outstanding' => $inquiry->outstanding(),
                'amount_online_usd' => $this->toFloat($inquiry->amount_online_usd),
                'amount_cash_usd' => $this->toFloat($inquiry->amount_cash_usd),
                'payment_split' => $inquiry->payment_split,
                'payment_method' => $inquiry->payment_method,
                'active_payment_attempt' => $this->paymentAttemptSection($inquiry->activePaymentAttempt),
            ],
            'timeline' => [
                'contacted_at' => $inquiry->contacted_at?->toIso8601String(),
                'confirmed_at' => $inquiry->confirmed_at?->toIso8601String(),
                'cancelled_at' => $inquiry->cancelled_at?->toIso8601String(),
                'paid_at' => $inquiry->paid_at?->toIso8601String(),
                'payment_link' => $inquiry->payment_link,
                'payment_link_sent_at' => $inquiry->payment_link_sent_at?->toIso8601String(),
                'internal_notes' => $inquiry->internal_notes,
                'operational_notes' => $inquiry->operational_notes,
            ],
            'assignment' => [
                'assigned_to' => $inquiry->assignedToUser?->name,
                'driver' => $inquiry->driver?->full_name ?? $inquiry->driver?->name,
                'guide' => $inquiry->guide?->full_name ?? $inquiry->guide?->name,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function tourProductSection(?TourProduct $product): ?array
    {
        if ($product === null) {
            return null;
        }

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'region' => $product->region,
            'tour_type' => $product->tour_type,
            'duration_days' => $product->duration_days,
            'duration_nights' => $product->duration_nights,
            'starting_from_usd' => $this->toFloat($product->starting_from_usd),
            'is_active' => (bool) $product->is_active,
            'highlights' => $product->highlights,
        ];
    }

    /**
     * Resolve the matched price tier for this inquiry and expose the full
     * tier table so the agent can quote alternative group sizes too.
     *
     * @return array<string,mixed>
     */
    private function quoteSection(BookingInquiry $inquiry, int $partySize): array
    {
        $product = $inquiry->tourProduct;

        if ($product === null) {
            return [
                'resolvable' => false,
                'reason' => 'inquiry has no linked tour_product',
                'party_size' => $partySize,
            ];
        }

        $type = $inquiry->tour_type
            ?? $product->tour_type
            ?? TourProduct::TYPE_PRIVATE;

        $directionCode = $inquiry->tourProductDirection?->code;

        $tier = $partySize > 0
            ? $product->priceFor($partySize, $directionCode, $type)
            : null;

        return [
            'resolvable' => $tier !== null,
            'party_size' => $partySize,
            'tour_type_used' => $type,
            'direction_code' => $directionCode,
            'matched_tier' => $tier === null ? null : [
                'group_size' => $tier->group_size,
                'price_per_person_usd' => $this->toFloat($tier->price_per_person_usd),
                'total_usd' => $this->toFloat($tier->totalForGroup()),
                'is_exact_match' => $tier->group_size === $partySize,
                'notes' => $tier->notes,
            ],
            'available_tiers' => $product->priceTiers
                ->where('is_active', true)
                ->map(fn (TourPriceTier $t): array => [
                    'group_size' => $t->group_size,
                    'price_per_person_usd' => $this->toFloat($t->price_per_person_usd),
                    'tour_type' => $t->tour_type,
                    'direction_id' => $t->tour_product_direction_id,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function paymentAttemptSection(?OctoPaymentAttempt $attempt): ?array
    {
        if ($attempt === null) {
            return null;
        }

        return [
            'transaction_id' => $attempt->transaction_id,
            'amount_online_usd' => $this->toFloat($attempt->amount_online_usd),
            'status' => $attempt->status,
            'created_at' => $attempt->created_at?->toIso8601String(),
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
