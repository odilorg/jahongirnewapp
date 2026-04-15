<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TourPriceTier;
use App\Models\TourProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Build and write the static-site tour pricing catalog.
 *
 * Transport model:
 *   The Laravel app and the jahongir-travel.uz static site live on the
 *   same VPS (Jahongir Contabo box). The export writes the generated PHP
 *   file directly to /domains/jahongir-travel.uz/data/tours.php via an
 *   atomic temp+rename on the same filesystem. No SSH, no network hop.
 *
 * Flow:
 *   buildPayload()   — query DB, shape array
 *   renderPhpFile()  — var_export wrapped in a banner
 *   commit()         — write $path.tmp, php -l it, rename() into place
 *
 * export() is the orchestrator called by both the artisan command today
 * and by any future model observer (Phase 8.3b-2).
 *
 * Pricing-only on purpose. Content fields (title, description, highlights)
 * stay in the static pages until we intentionally migrate them.
 */
class TourCatalogExportService
{
    /**
     * @param  array<int, string>|null  $onlySlugs  restrict to listed slugs, null = all
     * @return array{tours_count:int, bytes:int, generated_at:string, target_path:string, pushed:bool, skipped:array<int,string>, rendered_php?:string}
     */
    public function export(?array $onlySlugs = null, bool $dryRun = false): array
    {
        $payload = $this->buildPayload($onlySlugs);
        $php     = $this->renderPhpFile($payload);
        $target  = $this->resolveTargetPath();

        if ($dryRun) {
            return [
                'tours_count'  => count($payload['tours']),
                'bytes'        => strlen($php),
                'generated_at' => $payload['generated_at'],
                'target_path'  => $target,
                'pushed'       => false,
                'skipped'      => $payload['_skipped'],
                'rendered_php' => $php,
            ];
        }

        $this->commit($php, $target);

        return [
            'tours_count'  => count($payload['tours']),
            'bytes'        => strlen($php),
            'generated_at' => $payload['generated_at'],
            'target_path'  => $target,
            'pushed'       => true,
            'skipped'      => $payload['_skipped'],
        ];
    }

    /**
     * Query DB and assemble the catalog array.
     *
     * Tours with no price tiers are silently skipped (logged). Tours with
     * is_active=false are excluded entirely.
     *
     * @param  array<int, string>|null  $onlySlugs
     * @return array{schema_version:int, generated_at:string, tours:array<string, array<string, mixed>>, _skipped:array<int, string>}
     */
    public function buildPayload(?array $onlySlugs = null): array
    {
        $query = TourProduct::query()
            ->where('is_active', true)
            ->with([
                'priceTiers' => fn ($q) => $q->orderBy('group_size'),
                'priceTiers.direction',
            ])
            ->orderBy('slug');

        if ($onlySlugs !== null && $onlySlugs !== []) {
            $query->whereIn('slug', $onlySlugs);
        }

        $tours   = [];
        $skipped = [];

        foreach ($query->get() as $product) {
            if ($product->priceTiers->isEmpty()) {
                Log::info('Tour skipped from export (no tiers)', ['slug' => $product->slug]);
                $skipped[] = $product->slug;
                continue;
            }

            $tours[$product->slug] = [
                'is_active'         => (bool) $product->is_active,
                'currency'          => 'USD',
                'starting_from_usd' => $this->formatPrice($product->starting_from_usd),
                'last_updated_at'   => optional($product->updated_at)->toIso8601String(),
                'directions'        => $this->buildDirections($product->priceTiers),
            ];
        }

        return [
            'schema_version' => (int) config('tour_export.schema_version', 1),
            'generated_at'   => Carbon::now()->toIso8601String(),
            'tours'          => $tours,
            '_skipped'       => $skipped,
        ];
    }

    /**
     * Group tiers by direction code → tour type → ordered array of tiers.
     *
     * Direction codes flow through verbatim from tour_product_directions.code
     * ('sam-bukhara', 'bukhara-sam', 'default', etc.) to avoid drift with the
     * admin UI and booking inquiries.
     *
     * @param  iterable<TourPriceTier>  $tiers
     * @return array<string, array<string, array<int, array{group_size:int, price_per_person_usd:int|float}>>>
     */
    private function buildDirections(iterable $tiers): array
    {
        $out = [];

        foreach ($tiers as $tier) {
            $code = $tier->direction?->code ?? 'default';
            $type = $tier->tour_type;

            $out[$code][$type][] = [
                'group_size'           => (int) $tier->group_size,
                'price_per_person_usd' => $this->formatPrice($tier->price_per_person_usd),
            ];
        }

        // Defensive sort; the query already orders by group_size.
        foreach ($out as $code => $types) {
            foreach ($types as $type => $_) {
                usort(
                    $out[$code][$type],
                    fn (array $a, array $b): int => $a['group_size'] <=> $b['group_size']
                );
            }
        }

        return $out;
    }

    /**
     * Serialize price naturally: integer when whole dollars, float when cents present.
     * Keeps us ready for cent-level pricing without a schema change.
     */
    private function formatPrice(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }

        $f = (float) $value;

        return $f == (int) $f ? (int) $f : $f;
    }

    /**
     * Render the payload as a valid PHP return file with a banner.
     */
    public function renderPhpFile(array $payload): string
    {
        // _skipped is internal — not exported.
        unset($payload['_skipped']);

        $exported = var_export($payload, true);

        $banner = <<<'BANNER'
<?php

declare(strict_types=1);

// AUTOGENERATED by `php artisan tours:export-website-data`.
// DO NOT EDIT BY HAND — changes will be overwritten on next export.
// Source of truth: jahongirnewapp TourProduct / TourPriceTier / TourProductDirection.


BANNER;

        return $banner . 'return ' . $exported . ';' . PHP_EOL;
    }

    /**
     * Absolute on-disk path where the catalog should land.
     */
    public function resolveTargetPath(): string
    {
        $root = rtrim((string) config('tour_export.site_root'), '/');
        $rel  = ltrim((string) config('tour_export.relative_data_path'), '/');

        return $root . '/' . $rel;
    }

    /**
     * Write the rendered PHP to a sibling temp file, lint it, then atomic
     * rename into place. Any failure leaves the live file untouched.
     */
    public function commit(string $php, string $targetPath): void
    {
        $dir = dirname($targetPath);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create target dir: {$dir}");
        }

        if (! is_writable($dir)) {
            throw new RuntimeException("Target dir not writable: {$dir}");
        }

        $suffix  = (string) config('tour_export.staging_suffix', '.tmp');
        $tmpPath = $targetPath . $suffix . '.' . uniqid('', true);

        if (file_put_contents($tmpPath, $php, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temp file: {$tmpPath}");
        }

        // Lint before going live — catches any var_export corner case.
        $lint = new Process(['php', '-l', $tmpPath]);
        $lint->setTimeout((float) config('tour_export.process_timeout', 15));
        $lint->run();

        if (! $lint->isSuccessful()) {
            $err = trim($lint->getErrorOutput() ?: $lint->getOutput());
            @unlink($tmpPath);
            throw new RuntimeException("Rendered PHP failed lint: {$err}");
        }

        // rename() is atomic on POSIX when source and destination are on
        // the same filesystem (they are — same directory).
        if (! @rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Atomic rename failed: {$tmpPath} → {$targetPath}");
        }
    }
}
