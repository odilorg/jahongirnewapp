<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Pure HTML/PHP string transformations used by the tour pricing loader
 * rollout. Every method is deterministic and idempotent, and throws on
 * any input shape it doesn't understand so the caller can refuse to
 * touch the file rather than guess.
 *
 * This class does NOT touch the filesystem. Unit tests pass strings in
 * and strings out.
 */
class StaticSiteEditor
{
    public const MARKER = 'tour_pricing_slug';

    /**
     * Return reasons this HTML is NOT safe to edit.
     *
     * Rules (all must pass):
     *   - exactly one <table class="yurt-price-card"> on the page
     *   - exactly one <?php $schema_json = '...'; ?> line at file start
     *   - that $schema_json block contains exactly one "offers":{...} object
     *   - that offers object contains exactly one "price":"N" field
     *   - loader marker is not already present (idempotency)
     *
     * @return array<int, string>  list of violations; empty = safe
     */
    public function preflight(string $html): array
    {
        $errors = [];

        if (str_contains($html, self::MARKER)) {
            $errors[] = 'already converted (tour_pricing_slug marker present)';
        }

        $yurtCount = substr_count($html, 'class="yurt-price-card"');
        if ($yurtCount !== 1) {
            $errors[] = "expected exactly one yurt-price-card table, found {$yurtCount}";
        }

        $schemaLine = $this->extractSchemaJsonLine($html);
        if ($schemaLine === null) {
            $errors[] = 'no $schema_json assignment found on its own line';
        } else {
            $offersCount = preg_match_all('/"offers"\s*:/', $schemaLine);
            if ($offersCount !== 1) {
                $errors[] = "schema_json has {$offersCount} offers blocks (expected 1)";
            }

            // Tolerate optional whitespace around : and inside the offers
            // object — the static site has a mix of minified and pretty-
            // printed JSON-LD.
            if (! preg_match('/"offers"\s*:\s*\{[^}]*"price"\s*:\s*"(\d+(?:\.\d+)?)"/', $schemaLine)) {
                $errors[] = 'schema_json offers.price is not a simple numeric string literal';
            }
        }

        return $errors;
    }

    /**
     * Prepend the pricing-loader setup block. Idempotent: returns the
     * original string unchanged if the marker is already present.
     */
    public function injectLoaderBlock(
        string $html,
        string $slug,
        string $direction,
        string $type,
    ): string {
        if (str_contains($html, self::MARKER)) {
            return $html;
        }

        $slugLit = var_export($slug, true);
        $dirLit  = var_export($direction, true);
        $typeLit = var_export($type, true);

        $block = <<<PHP
<?php
// Pricing is sourced from jahongirnewapp admin via the exported catalog.
// See ../includes/tour-pricing-loader.php and ../data/tours.php.
\$tour_pricing_slug      = {$slugLit};
\$tour_pricing_direction = {$dirLit};
\$tour_pricing_type      = {$typeLit};
require __DIR__ . '/../includes/tour-pricing-loader.php';
?>

PHP;

        return $block . $html;
    }

    /**
     * Replace the unique yurt-price-card <tbody>...</tbody> block with a
     * conditional render that falls back to the original hardcoded rows
     * when the catalog file is missing.
     *
     * Throws if:
     *   - no yurt-price-card table found
     *   - more than one yurt-price-card table found
     *   - the tbody markers are missing or malformed
     */
    public function replacePriceCardTbody(string $html): string
    {
        $yurtCount = substr_count($html, 'class="yurt-price-card"');
        if ($yurtCount !== 1) {
            throw new RuntimeException("expected 1 yurt-price-card table, found {$yurtCount}");
        }

        // Find the unique yurt-price-card <table ... class="yurt-price-card">
        // and isolate its <tbody>...</tbody> block.
        $tablePos = strpos($html, 'class="yurt-price-card"');
        if ($tablePos === false) {
            throw new RuntimeException('yurt-price-card anchor not found');
        }

        $bodyOpenPos = strpos($html, '<tbody>', $tablePos);
        if ($bodyOpenPos === false) {
            throw new RuntimeException('yurt-price-card <tbody> opening not found');
        }

        $bodyCloseTag = '</tbody>';
        $bodyClosePos = strpos($html, $bodyCloseTag, $bodyOpenPos);
        if ($bodyClosePos === false) {
            throw new RuntimeException('yurt-price-card </tbody> closing not found');
        }

        // Quick safety: nothing suspicious should be between the open and
        // close — no nested <table>, no <?php inside the tbody already.
        $innerStart = $bodyOpenPos + strlen('<tbody>');
        $innerLen   = $bodyClosePos - $innerStart;
        $inner      = substr($html, $innerStart, $innerLen);

        if (str_contains($inner, '<table') || str_contains($inner, '<?php')) {
            throw new RuntimeException('yurt-price-card tbody contains nested markup we refuse to edit');
        }

        // Keep the original inner bytes verbatim as the fallback — that way
        // if the export file is missing, the page renders byte-identically
        // to the pre-conversion version.
        $fallback = rtrim($inner, "\n\r\t ");

        $replacement =
            '<tbody>' . "\n"
            . '<?php if (!empty($tour_pricing_tiers)): ?>' . "\n"
            . '<?php $__last = count($tour_pricing_tiers) - 1; foreach ($tour_pricing_tiers as $__i => $__tier): ?>' . "\n"
            . '<?= tour_pricing_render_row((int) $__tier["group_size"], $__tier["price_per_person_usd"], $__i === $__last) ?>' . "\n"
            . '<?php endforeach; ?>' . "\n"
            . '<?php else: ?>'
            . $fallback . "\n"
            . '<?php endif; ?>' . "\n"
            . '</tbody>';

        // Splice: replace the entire <tbody>...</tbody> with our replacement.
        $outerLen = ($bodyClosePos + strlen($bodyCloseTag)) - $bodyOpenPos;

        return substr_replace($html, $replacement, $bodyOpenPos, $outerLen);
    }

    /**
     * Replace the price field inside the $schema_json offers object.
     *
     * Strictly anchored: only touches the $schema_json assignment line
     * (not faq_schema, breadcrumb_schema, or any other ld+json block).
     * Refuses to touch the file if the anchor shape isn't the expected
     * single-line PHP string assignment with a single offers block.
     *
     * Idempotent: returns input unchanged if the price already matches.
     */
    public function syncOffersPrice(string $html, int|float $newPrice): string
    {
        $line = $this->extractSchemaJsonLine($html);
        if ($line === null) {
            throw new RuntimeException('no $schema_json line found');
        }

        if (preg_match_all('/"offers"\s*:/', $line) !== 1) {
            throw new RuntimeException('$schema_json has != 1 offers blocks');
        }

        $priceStr = (int) $newPrice == (float) $newPrice
            ? (string) (int) $newPrice
            : (string) (float) $newPrice;

        $newLine = preg_replace_callback(
            '/("offers"\s*:\s*\{[^}]*"price"\s*:\s*")(\d+(?:\.\d+)?)(")/',
            fn (array $m): string => $m[1] . $priceStr . $m[3],
            $line,
            1,
            $count
        );

        if ($count !== 1 || $newLine === null) {
            throw new RuntimeException('offers.price substitution did not match exactly once');
        }

        if ($newLine === $line) {
            return $html;
        }

        return str_replace($line, $newLine, $html);
    }

    /**
     * Return the exact substring of the file that starts with
     * `<?php $schema_json` and ends with `?>`, or null if not found.
     *
     * We anchor on a single line near the top of the file so we cannot
     * accidentally match inside FAQPage or BreadcrumbList schemas.
     */
    public function extractSchemaJsonLine(string $html): ?string
    {
        // Look for the literal prefix at the start or shortly after BOM.
        $prefix = '<?php $schema_json';
        $pos    = strpos($html, $prefix);
        if ($pos === false) {
            return null;
        }

        $end = strpos($html, "?>", $pos);
        if ($end === false) {
            return null;
        }

        return substr($html, $pos, $end - $pos + 2);
    }
}
