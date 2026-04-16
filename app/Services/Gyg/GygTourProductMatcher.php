<?php

declare(strict_types=1);

namespace App\Services\Gyg;

use App\Models\TourProduct;
use App\Models\TourProductDirection;

/**
 * Confidence-based tour product matching for GYG inbound emails.
 *
 * GYG emails contain a product title (e.g. "798008 [YUT-01] Samarkand:
 * 2-Day Desert Yurt Camp & Camel Ride Tour") and an option title (e.g.
 * "[default] Private 2-Day Desert Yurt Camp Journey"). This matcher
 * resolves those to our TourProduct + direction + type.
 *
 * Matching order (highest confidence first):
 *   1. GYG product code from title prefix → hardcoded alias map
 *   2. Option text keywords → tour type + direction hint
 *   3. Tour name partial match against tour_products.title
 *   4. No match → still create the inquiry, just unlinked
 */
class GygTourProductMatcher
{
    /**
     * GYG product code → our catalog. There are only 3 GYG products today.
     * Add rows here when new GYG listings are created.
     */
    private const CODE_MAP = [
        '798008' => ['slug' => 'yurt-camp-tour',    'direction' => 'sam-bukhara'],
        '619840' => ['slug' => 'daytrip-shahrisabz', 'direction' => 'default'],
        '830169' => ['slug' => 'bukhara-city-tour',  'direction' => 'default'],
    ];

    /**
     * @return array{
     *   product_id: ?int,
     *   direction_id: ?int,
     *   tour_type: ?string,
     *   slug: ?string,
     *   confidence: string,
     *   match_source: string,
     * }
     */
    public function match(?string $gygTourName, ?string $optionTitle): array
    {
        $tourType    = $this->detectTourType($optionTitle);
        $result      = $this->emptyResult($tourType);

        // Priority 1: extract GYG product code from tour name prefix ("798008 [YUT-01] ...")
        $code = $this->extractProductCode($gygTourName);
        if ($code && isset(self::CODE_MAP[$code])) {
            $map = self::CODE_MAP[$code];

            return $this->resolveFromSlug(
                $map['slug'],
                $map['direction'],
                $tourType,
                'exact',
                "gyg_code={$code}",
            );
        }

        // Priority 2: keyword match from option title
        $keywordSlug = $this->matchByKeyword($optionTitle ?? $gygTourName ?? '');
        if ($keywordSlug) {
            return $this->resolveFromSlug(
                $keywordSlug,
                'default',
                $tourType,
                'keyword',
                'option_keyword',
            );
        }

        // Priority 3: partial match against tour_products.title
        if ($gygTourName) {
            $partial = $this->partialTitleMatch($gygTourName);
            if ($partial) {
                return [
                    'product_id'   => $partial->id,
                    'direction_id' => null,
                    'tour_type'    => $tourType,
                    'slug'         => $partial->slug,
                    'confidence'   => 'ambiguous',
                    'match_source' => 'partial_title',
                ];
            }
        }

        // Priority 4: no match
        return $result;
    }

    /**
     * Detect private vs group from option text.
     */
    private function detectTourType(?string $optionTitle): ?string
    {
        if (! $optionTitle) {
            return null;
        }

        $lower = strtolower($optionTitle);

        if (str_contains($lower, 'private')) {
            return 'private';
        }
        if (str_contains($lower, 'group')) {
            return 'group';
        }

        return null;
    }

    /**
     * Extract the numeric product code from a GYG tour name.
     * Format: "798008 [YUT-01] Samarkand: 2-Day Desert Yurt Camp..."
     */
    private function extractProductCode(?string $tourName): ?string
    {
        if (! $tourName) {
            return null;
        }

        if (preg_match('/^(\d{4,8})\s/', $tourName, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Try to match tour by known keywords in the text.
     */
    private function matchByKeyword(string $text): ?string
    {
        $lower = strtolower($text);

        $keywordMap = [
            'yurt camp'  => 'yurt-camp-tour',
            'yurt'       => 'yurt-camp-tour',
            'shahrisabz' => 'daytrip-shahrisabz',
            'bukhara'    => 'bukhara-city-tour',
        ];

        foreach ($keywordMap as $keyword => $slug) {
            if (str_contains($lower, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Fuzzy partial match against tour_products.title.
     */
    private function partialTitleMatch(string $gygTourName): ?TourProduct
    {
        $prefix = mb_strtolower(mb_substr($gygTourName, 0, 30));

        return TourProduct::query()
            ->whereRaw('LOWER(title) LIKE ?', ["%{$prefix}%"])
            ->first();
    }

    /**
     * Given a slug + direction code, resolve to product/direction IDs.
     */
    private function resolveFromSlug(
        string $slug,
        string $directionCode,
        ?string $tourType,
        string $confidence,
        string $matchSource,
    ): array {
        $product = TourProduct::where('slug', $slug)->first();
        if (! $product) {
            return $this->emptyResult($tourType, 'none', "slug_not_found={$slug}");
        }

        $direction = $product->directions()
            ->where('code', $directionCode)
            ->first();

        return [
            'product_id'   => $product->id,
            'direction_id' => $direction?->id,
            'tour_type'    => $tourType,
            'slug'         => $slug,
            'confidence'   => $confidence,
            'match_source' => $matchSource,
        ];
    }

    private function emptyResult(?string $tourType, string $confidence = 'none', string $matchSource = 'no_match'): array
    {
        return [
            'product_id'   => null,
            'direction_id' => null,
            'tour_type'    => $tourType,
            'slug'         => null,
            'confidence'   => $confidence,
            'match_source' => $matchSource,
        ];
    }
}
