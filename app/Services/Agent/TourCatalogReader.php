<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\TourPriceTier;
use App\Models\TourProduct;

/**
 * Read-only reader over the tour catalog for the tour-agent (Phase 2).
 *
 * Backs agent:tour-catalog / agent:tour-prices / agent:quote-calculate. Strictly
 * read-only: no writes, no external calls, no PII. Quoting reuses
 * TourProduct::priceFor() — the SAME single source of truth as
 * agent:inquiry-context and the human Filament quote, so they can never
 * disagree. An unresolved quote returns resolvable=false / manual_quote_needed —
 * it NEVER guesses a price (no group tiers, no-tier custom tour, or no matching
 * tier all surface honestly).
 */
class TourCatalogReader
{
    public const EAGER = ['priceTiers', 'directions'];

    /** @return array<string,mixed> List active tours (optionally one by slug). */
    public function catalog(?string $slug = null): array
    {
        $query = TourProduct::with(self::EAGER)
            ->where('is_active', true)
            ->orderBy('region')->orderBy('slug');

        if ($slug !== null) {
            $query->where('slug', $slug);
        }

        $tours = $query->get();

        return [
            'count' => $tours->count(),
            'tours' => $tours->map(fn (TourProduct $p): array => $this->summary($p))->all(),
        ];
    }

    /** @return array<string,mixed>|null Tier breakdown for one tour (null if not found). */
    public function prices(string $slug): ?array
    {
        $product = TourProduct::with(self::EAGER)->where('slug', $slug)->first();
        if ($product === null) {
            return null;
        }

        $active = $product->priceTiers->where('is_active', true);

        return [
            'slug' => $product->slug,
            'title' => $this->clean($product->title),
            'tour_type' => $product->tour_type,
            'has_tiers' => $active->isNotEmpty(),
            'has_group_tiers' => $active->where('tour_type', 'group')->isNotEmpty(),
            'manual_quote' => $active->isEmpty(),
            'directions' => $product->directions->where('is_active', true)->pluck('code')->values()->all(),
            'tiers' => $active->sortBy('group_size')->map(fn (TourPriceTier $t): array => [
                'group_size' => $t->group_size,
                'price_per_person_usd' => $this->toFloat($t->price_per_person_usd),
                'total_for_group' => $this->toFloat($t->totalForGroup()),
                'tour_type' => $t->tour_type,
                'direction' => $this->directionCode($product, $t->tour_product_direction_id),
                'notes' => $t->notes,
                'is_active' => (bool) $t->is_active,
            ])->values()->all(),
        ];
    }

    /**
     * Resolve a price for a hypothetical {tour, party, direction, type}.
     * No inquiry needed. Returns a matched tier or an honest manual_quote_needed.
     *
     * @return array<string,mixed>
     */
    public function quote(string $slug, int $party, ?string $direction = 'default', string $type = TourProduct::TYPE_PRIVATE): array
    {
        $product = TourProduct::with(self::EAGER)->where('slug', $slug)->first();
        if ($product === null) {
            return [
                'resolvable' => false,
                'manual_quote_needed' => true,
                'reason' => 'tour_not_found',
                'tour_slug' => $slug,
                'party_size' => $party,
            ];
        }

        $direction = $direction !== null && $direction !== '' ? $direction : 'default';
        $type = $type !== '' ? $type : ($product->tour_type ?? TourProduct::TYPE_PRIVATE);
        $active = $product->priceTiers->where('is_active', true);

        $tier = $party >= 1 ? $product->priceFor($party, $direction, $type) : null;

        $reason = null;
        if ($tier === null) {
            $reason = match (true) {
                $party < 1 => 'invalid_party_size',
                $active->isEmpty() => 'no_tiers_custom_tour',
                $active->where('tour_type', $type)->isEmpty() => "no_{$type}_tiers",
                default => 'no_matching_tier',
            };
        }

        return [
            'resolvable' => $tier !== null,
            'manual_quote_needed' => $tier === null,
            'reason' => $reason,
            'tour_slug' => $product->slug,
            'party_size' => $party,
            'tour_type_used' => $type,
            'direction_code' => $direction,
            'matched_tier' => $tier === null ? null : [
                'group_size' => $tier->group_size,
                'price_per_person_usd' => $this->toFloat($tier->price_per_person_usd),
                'total_usd' => $this->toFloat($tier->totalForGroup()),
                'is_exact_match' => $tier->group_size === $party,
                'notes' => $tier->notes,
            ],
            'available_tiers' => $active->sortBy('group_size')->map(fn (TourPriceTier $t): array => [
                'group_size' => $t->group_size,
                'price_per_person_usd' => $this->toFloat($t->price_per_person_usd),
                'tour_type' => $t->tour_type,
                'direction' => $this->directionCode($product, $t->tour_product_direction_id),
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(TourProduct $product): array
    {
        $active = $product->priceTiers->where('is_active', true);

        return [
            'slug' => $product->slug,
            'title' => $this->clean($product->title),
            'region' => $product->region,
            'tour_type' => $product->tour_type,
            'duration_days' => $product->duration_days,
            'duration_nights' => $product->duration_nights,
            'starting_from_usd' => $this->toFloat($product->starting_from_usd),
            'is_active' => (bool) $product->is_active,
            'has_tiers' => $active->isNotEmpty(),
            'manual_quote' => $active->isEmpty(),
            'directions' => $product->directions->where('is_active', true)->pluck('code')->values()->all(),
            'includes' => $this->bullets($product->includes),
            'excludes' => $this->bullets($product->excludes),
            'highlights' => $this->highlights($product->highlights),
        ];
    }

    private function directionCode(TourProduct $product, ?int $directionId): string
    {
        if ($directionId === null) {
            return 'GLOBAL';
        }

        return $product->directions->firstWhere('id', $directionId)?->code ?? "dir:{$directionId}";
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function clean(?string $text): string
    {
        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<string> Split a bulleted text field into clean lines. */
    private function bullets(?string $text): array
    {
        $decoded = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $decoded) ?: [] as $line) {
            $clean = trim(ltrim(trim($line), "•-*\t "));
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function highlights(mixed $highlights): array
    {
        if (is_array($highlights)) {
            return array_values(array_filter(
                array_map(fn ($x): string => trim((string) $x), $highlights),
                fn (string $x): bool => $x !== '',
            ));
        }

        return $this->bullets((string) $highlights);
    }
}
