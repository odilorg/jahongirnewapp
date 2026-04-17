<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Models\TourPriceTier;
use App\Models\TourProduct;
use Illuminate\Support\Carbon;

/**
 * Normalized presentation DTO for the tour datasheet PDF.
 *
 * The Blade template renders ONLY this object. Business logic (DB
 * queries, decimal formatting, slug resolution, highlight cleanup)
 * stays here so the view file never reaches into models directly.
 *
 * When the rendering engine swaps from dompdf to Browsershot, or when
 * the admin schema grows new fields, the contract this class exposes
 * is the only thing templates need to track.
 *
 * Intentionally immutable and free of service dependencies so it can
 * be serialized to a queue payload later (Phase 2 auto-regeneration).
 */
final class TourPdfViewModel
{
    /**
     * @param  array<int, string>  $highlights
     * @param  array<int, string>  $includes
     * @param  array<int, string>  $excludes
     * @param  array<int, array{group_size:int, label:string, price_usd:int|float, is_last:bool}>  $priceTiers
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $heroImageUrl,
        public readonly ?int $durationDays,
        public readonly ?int $durationNights,
        public readonly string $durationLabel,
        public readonly ?string $description,
        public readonly array $highlights,
        public readonly array $includes,
        public readonly array $excludes,
        public readonly array $priceTiers,
        public readonly string $currency,
        public readonly ?string $pageUrl,
        public readonly string $generatedAt,
        public readonly string $generatedAtHuman,
        public readonly string $contentHash,
    ) {
    }

    /**
     * Build from an already-loaded TourProduct. Caller is responsible
     * for eager-loading `priceTiers` (and their direction) so this
     * method never issues extra queries.
     */
    public static function fromModel(TourProduct $product, Carbon $generatedAt): self
    {
        $tiers = self::shapeTiers($product->priceTiers);

        // Deterministic hash of the material content — used for optional
        // skip-if-unchanged optimization (Phase 2) and for embedding in
        // PDF metadata so drift is trivially detectable.
        $hash = substr(hash('sha256', json_encode([
            'slug'     => $product->slug,
            'title'    => $product->title,
            'desc'     => $product->description,
            'hl'       => $product->highlights ?? [],
            'incl'     => self::splitList((string) $product->includes),
            'excl'     => self::splitList((string) $product->excludes),
            'tiers'    => $tiers,
            'duration' => [$product->duration_days, $product->duration_nights],
        ], JSON_UNESCAPED_UNICODE)), 0, 12);

        return new self(
            slug: (string) $product->slug,
            title: (string) $product->title,
            heroImageUrl: self::nullIfEmpty($product->hero_image_url),
            durationDays: $product->duration_days !== null ? (int) $product->duration_days : null,
            durationNights: $product->duration_nights !== null ? (int) $product->duration_nights : null,
            durationLabel: self::formatDuration($product->duration_days, $product->duration_nights),
            description: self::nullIfEmpty($product->description),
            highlights: self::cleanListArray($product->highlights ?? []),
            includes: self::splitList((string) $product->includes),
            excludes: self::splitList((string) $product->excludes),
            priceTiers: $tiers,
            currency: $product->currency ?: 'USD',
            pageUrl: self::nullIfEmpty($product->page_url),
            generatedAt: $generatedAt->toIso8601String(),
            generatedAtHuman: $generatedAt->format('F j, Y'),
            contentHash: $hash,
        );
    }

    /**
     * Pick the default-direction, private-type tiers. Matches the
     * resolution order TourCatalogExportService uses for `tours.php`
     * so the PDF always agrees with the website.
     *
     * Tiers always belong to a direction record in this schema — the
     * "default" direction has code='default'. A literal NULL direction_id
     * is treated the same way for resilience.
     *
     * @param  iterable<TourPriceTier>  $tiers
     * @return array<int, array{group_size:int, label:string, price_usd:int|float, is_last:bool}>
     */
    private static function shapeTiers(iterable $tiers): array
    {
        $selected = [];

        foreach ($tiers as $tier) {
            if (! $tier->is_active) {
                continue;
            }
            if ($tier->tour_type !== TourProduct::TYPE_PRIVATE) {
                continue;
            }
            // Take default-direction tiers only. Non-default directions
            // (e.g. sam-bukhara) are variants; the datasheet shows the
            // base price just like the website does.
            $directionCode = $tier->direction?->code;
            if ($directionCode !== null && $directionCode !== 'default') {
                continue;
            }

            $selected[] = [
                'group_size' => (int) $tier->group_size,
                'price_usd'  => self::formatPrice($tier->price_per_person_usd),
            ];
        }

        usort(
            $selected,
            fn (array $a, array $b): int => $a['group_size'] <=> $b['group_size']
        );

        $count = count($selected);
        $out   = [];
        foreach ($selected as $i => $row) {
            $isLast = ($i === $count - 1);
            $out[] = [
                'group_size' => $row['group_size'],
                'label'      => self::tierLabel($row['group_size'], $isLast),
                'price_usd'  => $row['price_usd'],
                'is_last'    => $isLast,
            ];
        }

        return $out;
    }

    private static function tierLabel(int $groupSize, bool $isLast): string
    {
        if ($groupSize === 1) {
            return '1 person';
        }

        return $isLast
            ? $groupSize . '+ persons'
            : $groupSize . ' persons';
    }

    private static function formatPrice(mixed $value): int|float
    {
        $f = (float) ($value ?? 0);

        return $f == (int) $f ? (int) $f : $f;
    }

    private static function formatDuration(mixed $days, mixed $nights): string
    {
        $d = $days !== null ? (int) $days : null;
        $n = $nights !== null ? (int) $nights : null;

        if ($d === null && $n === null) {
            return 'Custom duration';
        }
        if ($d !== null && $n === null) {
            return $d === 1 ? '1 day' : "{$d} days";
        }
        if ($d === null && $n !== null) {
            return $n === 1 ? '1 night' : "{$n} nights";
        }

        return "{$d} days / {$n} nights";
    }

    /**
     * Split multi-line textarea content into trimmed, non-empty lines.
     * The admin stores includes/excludes as plain text with one item
     * per line; the PDF renders them as bullets.
     *
     * @return array<int, string>
     */
    private static function splitList(string $blob): array
    {
        if ($blob === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $blob) ?: [] as $line) {
            $line = trim($line);
            // Strip leading bullet markers the operator may have pasted in.
            $line = preg_replace('/^[-•✓✗*]\s*/u', '', $line) ?? $line;
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Highlights can be a plain array or a JSON-cast array of strings/objects.
     *
     * @param  array<int|string, mixed>  $raw
     * @return array<int, string>
     */
    private static function cleanListArray(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $s = trim($item);
                if ($s !== '') {
                    $out[] = $s;
                }
            } elseif (is_array($item) && isset($item['label'])) {
                $s = trim((string) $item['label']);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    private static function nullIfEmpty(?string $v): ?string
    {
        $v = $v !== null ? trim($v) : null;

        return ($v === null || $v === '') ? null : $v;
    }
}
