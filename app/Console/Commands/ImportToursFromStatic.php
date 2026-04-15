<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourPriceTier;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 8.1 — Import tour catalog from static PHP pages on the
 * jahongir-travel.uz website.
 *
 * Usage:
 *   php artisan tours:import-from-static             # real import
 *   php artisan tours:import-from-static --dry       # parse + preview, no DB writes
 *   php artisan tours:import-from-static --force     # overwrite operator-edited records
 *   php artisan tours:import-from-static --path=/... # scan a single file (testing)
 *
 * Safety rules (per Phase 8.1 plan):
 *   - upsert by slug (slug = filename without .php)
 *   - page_url reconstructed from the file path
 *   - source_type=website_static, source_path filled
 *   - one default direction per tour (code=default) unless confidently
 *     inferable; we deliberately do NOT guess sam-bukhara vs bukhara-sam
 *     from weak signals because wrong directions silently corrupt pricing
 *   - imported price tiers attach to the default direction, type=private
 *   - drift detection: if DB row diverges from last-imported hash,
 *     skip unless --force (protects operator edits)
 *   - final report: created / updated / skipped / failed with file paths
 */
class ImportToursFromStatic extends Command
{
    protected $signature = 'tours:import-from-static
        {--dry : Parse everything but do not write to DB}
        {--force : Overwrite records even if operator edits have diverged from last import}
        {--path= : Scan a single file path instead of the default glob}';

    protected $description = 'Import tour catalog from jahongir-travel.uz static PHP tour pages.';

    private const BASE_PATH = '/domains/jahongir-travel.uz';

    // Which directories to scan + how to map them to regions
    private const REGION_MAP = [
        'tours-from-samarkand'   => 'samarkand',
        'tours-from-bukhara'     => 'bukhara',
        'tours-from-khiva'       => 'khiva',
        'tours-from-nukus'       => 'nukus',
        'uzbekistan-tours'       => 'uzbekistan',
        'tajikistan-tours'       => 'tajikistan',
    ];

    // Filenames we never want to treat as tour pages
    private const SKIP_FILES = [
        'index.php', 'index', 'header.php', 'footer.php',
        'mailer-tours.php', 'booking.php', 'contact.php',
    ];

    private array $report = [
        'created' => [],
        'updated' => [],
        'skipped' => [],
        'failed'  => [],
    ];

    public function handle(): int
    {
        $isDry   = (bool) $this->option('dry');
        $isForce = (bool) $this->option('force');
        $only    = $this->option('path');

        $files = $only ? [$only] : $this->discoverFiles();

        if ($files === []) {
            $this->error('No tour pages discovered. Check BASE_PATH on the VPS.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %d file(s)%s%s',
            $isDry ? 'DRY-RUN scanning' : 'Importing',
            count($files),
            $isForce ? ' [--force]' : '',
            $only ? " [--path={$only}]" : '',
        ));

        foreach ($files as $file) {
            try {
                $this->processFile($file, $isDry, $isForce);
            } catch (\Throwable $e) {
                $this->report['failed'][] = [
                    'file'  => $file,
                    'error' => $e->getMessage(),
                ];
                $this->error("FAILED {$file}: {$e->getMessage()}");
            }
        }

        $this->printReport();

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function discoverFiles(): array
    {
        $found = [];

        foreach (array_keys(self::REGION_MAP) as $dir) {
            $globPath = self::BASE_PATH . '/' . $dir . '/*.php';
            $matches  = glob($globPath) ?: [];

            foreach ($matches as $path) {
                $basename = basename($path);

                if (in_array($basename, self::SKIP_FILES, true)) {
                    continue;
                }
                if (str_contains($basename, '.bak')) {
                    continue;
                }

                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    private function processFile(string $path, bool $isDry, bool $isForce): void
    {
        if (! is_file($path)) {
            $this->report['failed'][] = ['file' => $path, 'error' => 'file not found'];

            return;
        }

        $raw     = file_get_contents($path) ?: '';
        $region  = $this->regionFromPath($path);
        $slug    = $this->slugFromPath($path);
        $parsed  = $this->parseTourContent($raw, $slug, $region, $path);
        $tiers   = $this->parsePriceTiers($raw);

        if ($parsed === null) {
            $this->report['skipped'][] = [
                'file'   => $path,
                'reason' => 'no parseable tour content (missing h1/title)',
            ];
            $this->warn("SKIP {$slug}: no parseable title/h1");

            return;
        }

        // Hash inputs so we can detect file changes and operator drift.
        $importHash = $this->buildImportHash($parsed, $tiers);

        /** @var TourProduct|null $existing */
        $existing = TourProduct::where('slug', $slug)->first();

        // Drift guard: if the row was previously imported and its stored
        // import_hash differs from the one we just computed BUT the file
        // ALSO differs from what we'd produce now, operator has edited
        // the row since last import → skip unless --force.
        if ($existing && $existing->source_type === 'website_static' && $existing->import_hash) {
            // Re-compute what the hash of the current DB row WOULD be if
            // we hashed its current field values the same way.
            $currentDbHash = $this->buildImportHashFromModel($existing);

            if ($existing->import_hash !== $currentDbHash && ! $isForce) {
                $this->report['skipped'][] = [
                    'file'   => $path,
                    'reason' => 'operator drift — DB row diverged from last import; re-run with --force to override',
                ];
                $this->warn("SKIP {$slug}: operator drift detected");

                return;
            }

            // File identical to last import → nothing to do.
            if ($existing->import_hash === $importHash) {
                $this->report['skipped'][] = [
                    'file'   => $path,
                    'reason' => 'unchanged since last import',
                ];
                $this->line("  · {$slug}: unchanged");

                return;
            }
        }

        // Manual records are never overwritten by the importer.
        if ($existing && $existing->source_type === 'manual' && ! $isForce) {
            $this->report['skipped'][] = [
                'file'   => $path,
                'reason' => 'existing row is source_type=manual; use --force to convert',
            ];
            $this->warn("SKIP {$slug}: manual-authored row");

            return;
        }

        if ($isDry) {
            $this->line(sprintf(
                '  + %s  %s  (%d tiers)%s',
                $slug,
                $parsed['title'],
                count($tiers),
                $existing ? ' [update]' : ' [create]',
            ));
            $this->report[$existing ? 'updated' : 'created'][] = ['file' => $path];

            return;
        }

        $attributes = $parsed + [
            'source_type'      => 'website_static',
            'source_path'      => str_replace(self::BASE_PATH, '', $path),
            'import_hash'      => $importHash,
            'last_imported_at' => now(),
            'is_active'        => $existing?->is_active ?? true,
            'sort_order'       => $existing?->sort_order ?? 0,
        ];

        $tour = TourProduct::updateOrCreate(['slug' => $slug], $attributes);
        $wasCreated = $tour->wasRecentlyCreated;

        // Default direction (we deliberately do NOT infer route direction
        // from filename patterns — too risky to silently attach prices to
        // the wrong route).
        $direction = $tour->directions()->updateOrCreate(
            ['code' => 'default'],
            [
                'name'       => 'Default route',
                'is_active'  => true,
                'sort_order' => 0,
                'notes'      => 'Auto-created by importer. Rename/replace once real route variants are known.',
            ]
        );

        // Replace imported tiers cleanly: remove any previously-imported
        // tiers on the default direction (identified by notes prefix) and
        // re-create from the current parse. Operator tiers on other
        // directions are untouched.
        $tour->priceTiers()
            ->where('tour_product_direction_id', $direction->id)
            ->where('notes', 'like', '[imported]%')
            ->delete();

        foreach ($tiers as $tier) {
            TourPriceTier::create([
                'tour_product_id'           => $tour->id,
                'tour_product_direction_id' => $direction->id,
                'tour_type'                 => TourProduct::TYPE_PRIVATE,
                'group_size'                => $tier['group_size'],
                'price_per_person_usd'      => $tier['price_per_person_usd'],
                'notes'                     => '[imported] ' . ($tier['label'] ?? ''),
                'is_active'                 => true,
            ]);
        }

        $tour->refresh()->recalculateStartingPrice();

        $this->report[$wasCreated ? 'created' : 'updated'][] = ['file' => $path];
        $this->line(sprintf(
            '  %s %s  "%s"  (%d tiers)',
            $wasCreated ? '+' : '~',
            $slug,
            Str::limit($tour->title, 60),
            count($tiers),
        ));
    }

    private function regionFromPath(string $path): string
    {
        foreach (self::REGION_MAP as $dir => $region) {
            if (str_contains($path, "/{$dir}/")) {
                return $region;
            }
        }

        return 'uzbekistan';
    }

    private function slugFromPath(string $path): string
    {
        return basename($path, '.php');
    }

    /**
     * @return array<string, mixed>|null  fields for TourProduct::fill() or null on parse failure
     */
    private function parseTourContent(string $raw, string $slug, string $region, string $path): ?array
    {
        // Title: prefer <h1>, fallback to $title PHP variable, fallback to $meta $title suffix stripped
        $h1 = null;
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $raw, $m)) {
            $h1 = trim(strip_tags($m[1]));
            $h1 = html_entity_decode($h1, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $phpTitle = null;
        if (preg_match('/\$title\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
            $phpTitle = trim($m[1]);
        }

        $title = $h1 ?: ($phpTitle ? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', $phpTitle) : null);

        if (! $title) {
            return null;
        }

        // Meta description
        $metaDescription = null;
        if (preg_match('/\$meta_description\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
            $metaDescription = trim($m[1]);
        }

        // Description: collect first few <p> tags after the opening <h1>
        $description = '';
        if (preg_match('/<h1[^>]*>.*?<\/h1>(.*?)(?:<div[^>]+price|<table[^>]+tours-tabs|<h2)/is', $raw, $m)) {
            $after = $m[1];
            preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $after, $pm);
            $paras = array_map(
                fn ($p) => trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $pm[1] ?? []
            );
            $paras = array_filter($paras, fn ($p) => mb_strlen($p) > 20);
            $description = implode("\n\n", array_slice($paras, 0, 5));
        }

        // Hero image — prefer JSON-LD schema
        $heroImage = null;
        if (preg_match('/"image"\s*:\s*"([^"]+)"/', $raw, $m)) {
            $heroImage = $m[1];
        }

        // Duration: look for "N-Day" / "N Day" / "N Days N Nights" patterns in the title
        $durationDays   = 1;
        $durationNights = 0;
        if (preg_match('/(\d+)\s*[- ]?\s*day/i', $title, $m)) {
            $durationDays = max(1, (int) $m[1]);
        }
        if (preg_match('/(\d+)\s*night/i', $title, $m)) {
            $durationNights = (int) $m[1];
        }

        $pageUrl = 'https://jahongir-travel.uz' . preg_replace(
            '#^' . preg_quote(self::BASE_PATH, '#') . '#',
            '',
            preg_replace('/\.php$/', '', $path)
        );

        return [
            'title'            => $this->cleanString($title),
            'region'           => $region,
            'tour_type'        => TourProduct::TYPE_PRIVATE,
            'duration_days'    => $durationDays,
            'duration_nights'  => $durationNights,
            'currency'         => 'USD',
            'description'      => $description ?: null,
            'highlights'       => $this->parseHighlights($raw),
            'includes'         => $this->parseIncludesExcludes($raw, 'INCLUDED'),
            'excludes'         => $this->parseIncludesExcludes($raw, 'NOT INCLUDED'),
            'hero_image_url'   => $heroImage,
            'page_url'         => $pageUrl,
            'meta_description' => $metaDescription,
        ];
    }

    /**
     * @return array<int, array{group_size: int, price_per_person_usd: float, label: string}>
     */
    private function parsePriceTiers(string $raw): array
    {
        if (! preg_match('/<table[^>]*class="[^"]*yurt-price-card[^"]*"[^>]*>(.*?)<\/table>/si', $raw, $tableMatch)) {
            return [];
        }

        $tableHtml = $tableMatch[1];
        // Keep only the <tbody> content
        if (preg_match('/<tbody[^>]*>(.*?)<\/tbody>/si', $tableHtml, $m)) {
            $tableHtml = $m[1];
        }

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $tableHtml, $rows);

        $tiers = [];
        foreach ($rows[1] as $row) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells);
            if (count($cells[1]) < 2) {
                continue;
            }

            $label = trim(strip_tags($cells[1][0]));
            $priceCell = trim(strip_tags($cells[1][1]));

            // group size = first integer in the label ("1 person", "2 persons", "3+ persons")
            if (! preg_match('/(\d+)/', $label, $gm)) {
                continue;
            }
            $groupSize = (int) $gm[1];

            // price = first decimal in the price cell
            if (! preg_match('/\$?\s*([\d]+(?:[.,]\d+)?)/', $priceCell, $pm)) {
                continue;
            }
            $price = (float) str_replace(',', '', $pm[1]);

            if ($groupSize < 1 || $price <= 0) {
                continue;
            }

            $tiers[] = [
                'group_size'           => $groupSize,
                'price_per_person_usd' => $price,
                'label'                => $label,
            ];
        }

        return $tiers;
    }

    /**
     * @return array<int, string>|null
     */
    private function parseHighlights(string $raw): ?array
    {
        // Heuristic: first <ul> after the main h1 often holds "Practical tips"
        // or "Highlights". Take short bullet items only, max 8.
        if (! preg_match('/<h1[^>]*>.*?<\/h1>(.*)/is', $raw, $m)) {
            return null;
        }

        if (! preg_match_all('/<ul[^>]*>(.*?)<\/ul>/si', $m[1], $uls)) {
            return null;
        }

        foreach ($uls[1] as $ul) {
            preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $ul, $lis);
            $items = array_map(
                fn ($li) => trim(html_entity_decode(strip_tags($li), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $lis[1] ?? []
            );
            $items = array_values(array_filter(
                $items,
                fn ($item) => mb_strlen($item) > 5 && mb_strlen($item) < 200
            ));

            if (count($items) >= 3 && count($items) <= 12) {
                return array_slice($items, 0, 8);
            }
        }

        return null;
    }

    private function parseIncludesExcludes(string $raw, string $label): ?string
    {
        if (! preg_match('/<td[^>]*>\s*<strong>\s*' . preg_quote($label, '/') . '\s*<\/strong>\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/si', $raw, $m)) {
            return null;
        }

        $cell = $m[1];
        // Strip nested tables, extract item text
        $cell = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $cell);
        $items = [];
        if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $cell, $tdm)) {
            foreach ($tdm[1] as $td) {
                $text = trim(html_entity_decode(strip_tags($td), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (mb_strlen($text) > 3 && mb_strlen($text) < 200) {
                    $items[] = '• ' . $text;
                }
            }
        }

        return $items ? implode("\n", $items) : null;
    }

    private function cleanString(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s ?? '');
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function buildImportHash(array $parsed, array $tiers): string
    {
        $payload = [
            'title'       => $parsed['title'] ?? null,
            'description' => $parsed['description'] ?? null,
            'hero'        => $parsed['hero_image_url'] ?? null,
            'includes'    => $parsed['includes'] ?? null,
            'excludes'    => $parsed['excludes'] ?? null,
            'highlights'  => $parsed['highlights'] ?? null,
            'tiers'       => array_map(
                fn ($t) => [$t['group_size'], (float) $t['price_per_person_usd']],
                $tiers,
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function buildImportHashFromModel(TourProduct $tour): string
    {
        $tour->loadMissing('priceTiers');

        $importedTiers = $tour->priceTiers
            ->filter(fn ($t) => str_starts_with((string) $t->notes, '[imported]'))
            ->map(fn ($t) => [$t->group_size, (float) $t->price_per_person_usd])
            ->values()
            ->all();

        $payload = [
            'title'       => $tour->title,
            'description' => $tour->description,
            'hero'        => $tour->hero_image_url,
            'includes'    => $tour->includes,
            'excludes'    => $tour->excludes,
            'highlights'  => $tour->highlights,
            'tiers'       => $importedTiers,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function printReport(): void
    {
        $this->newLine();
        $this->info('──────────────────────────────────────────────');
        $this->info(sprintf(
            'Created: %d   Updated: %d   Skipped: %d   Failed: %d',
            count($this->report['created']),
            count($this->report['updated']),
            count($this->report['skipped']),
            count($this->report['failed']),
        ));
        $this->info('──────────────────────────────────────────────');

        if ($this->report['skipped']) {
            $this->newLine();
            $this->warn('Skipped files:');
            foreach ($this->report['skipped'] as $row) {
                $this->line("  - {$row['file']}");
                $this->line("    reason: {$row['reason']}");
            }
        }

        if ($this->report['failed']) {
            $this->newLine();
            $this->error('Failed files:');
            foreach ($this->report['failed'] as $row) {
                $this->line("  - {$row['file']}");
                $this->line("    error: {$row['error']}");
            }
        }
    }
}
