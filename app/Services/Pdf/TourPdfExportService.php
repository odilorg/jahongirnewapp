<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Models\TourProduct;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Render tour datasheet PDFs and write them to the static-site
 * filesystem. Pair this with TourCatalogExportService: the catalog
 * writer updates tours.php, this writer updates the downloadable PDFs,
 * so a single "Export website data" action keeps both in sync.
 *
 * Transport model:
 *   The Laravel app and jahongir-travel.uz live on the same VPS, so we
 *   write directly to the local filesystem with temp+rename (atomic on
 *   POSIX when source and target share a filesystem — same directory).
 *   No SSH, no network hop.
 *
 * Pipeline per tour:
 *   1. Hydrate TourPdfViewModel (all business logic, one place).
 *   2. Render Blade → HTML → PDF bytes (dompdf for v1, swappable).
 *   3. Sanity-check byte count against pdf_min_bytes.
 *   4. Write to `$target.tmp.<uniqid>` in the same directory.
 *   5. rename() into place. Failure leaves the live PDF untouched.
 *
 * Per-tour failures are logged and recorded in the result — a bad tour
 * never kills the whole batch.
 */
class TourPdfExportService
{
    /**
     * @param  array<int, string>|null  $onlySlugs   restrict to listed slugs, null = all PDF-enabled
     * @param  string|null              $outputRoot  override for preview/test runs (config default otherwise)
     * @return array{
     *   generated_at:string,
     *   exported:array<int, array{slug:string, path:string, bytes:int, hash:string}>,
     *   skipped:array<int, array{slug:string, reason:string}>,
     *   failed:array<int, array{slug:string, error:string}>,
     * }
     */
    public function export(
        ?array $onlySlugs = null,
        bool $dryRun = false,
        ?string $outputRoot = null,
    ): array {
        $generatedAt = Carbon::now();
        $root        = rtrim($outputRoot ?? (string) config('tour_export.pdf_output_root'), '/');
        $minBytes    = (int) config('tour_export.pdf_min_bytes', 8000);

        $query = TourProduct::query()
            ->where('is_active', true)
            ->where('pdf_enabled', true)
            ->whereNotNull('pdf_relative_path')
            ->with([
                'priceTiers' => fn ($q) => $q->orderBy('group_size'),
                'priceTiers.direction',
            ])
            ->orderBy('slug');

        if ($onlySlugs !== null && $onlySlugs !== []) {
            $query->whereIn('slug', $onlySlugs);
        }

        $exported = [];
        $skipped  = [];
        $failed   = [];

        foreach ($query->get() as $product) {
            $slug = (string) $product->slug;

            if ($product->priceTiers->isEmpty()) {
                $skipped[] = ['slug' => $slug, 'reason' => 'no price tiers'];
                Log::info('Tour PDF skipped (no tiers)', ['slug' => $slug]);
                continue;
            }

            try {
                $vm    = TourPdfViewModel::fromModel($product, $generatedAt);
                $bytes = $this->render($vm);

                if (strlen($bytes) < $minBytes) {
                    throw new RuntimeException(sprintf(
                        'Rendered PDF smaller than pdf_min_bytes (%d < %d).',
                        strlen($bytes),
                        $minBytes
                    ));
                }

                $relative = ltrim((string) $product->pdf_relative_path, '/');
                $target   = $root . '/' . $relative;

                if (! $dryRun) {
                    $this->commit($bytes, $target);
                }

                $exported[] = [
                    'slug'  => $slug,
                    'path'  => $target,
                    'bytes' => strlen($bytes),
                    'hash'  => $vm->contentHash,
                ];
            } catch (Throwable $e) {
                $failed[] = ['slug' => $slug, 'error' => $e->getMessage()];
                Log::error('Tour PDF export failed', [
                    'slug'  => $slug,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'exported'     => $exported,
            'skipped'      => $skipped,
            'failed'       => $failed,
        ];
    }

    /**
     * Render a single ViewModel to PDF bytes. Swap the engine here —
     * nothing else in the pipeline needs to know what produced the bytes.
     */
    public function render(TourPdfViewModel $vm): string
    {
        return Pdf::loadView('pdf.tour-datasheet', ['tour' => $vm])
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }

    /**
     * Write bytes to `$target.tmp.<uniqid>` in the same directory, then
     * atomic-rename into place. rename() is atomic on POSIX when source
     * and destination share a filesystem — we guarantee that by staging
     * inside the target's own dir.
     */
    protected function commit(string $bytes, string $targetPath): void
    {
        $dir = dirname($targetPath);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create target dir: {$dir}");
        }

        if (! is_writable($dir)) {
            throw new RuntimeException("Target dir not writable: {$dir}");
        }

        $tmp = $targetPath . '.tmp.' . uniqid('', true);

        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temp PDF: {$tmp}");
        }

        // Quick signature sanity check before going live.
        if (substr($bytes, 0, 4) !== '%PDF') {
            @unlink($tmp);
            throw new RuntimeException("Rendered bytes are not a valid PDF (missing %PDF header)");
        }

        if (! @rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new RuntimeException("Atomic rename failed: {$tmp} → {$targetPath}");
        }
    }
}
