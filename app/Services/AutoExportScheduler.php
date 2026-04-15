<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Request-scoped coalescer + after-response runner for the tour pricing
 * auto-export pipeline.
 *
 * Goal: when an operator clicks Save in Filament, the DB change commits,
 * Filament renders the updated row, the HTTP response is flushed to the
 * browser, and THEN the static-site catalog is regenerated and the
 * fastcgi cache is purged. The admin UX sees zero latency.
 *
 * Rules:
 *   - HTTP-only. CLI is explicitly excluded: any artisan/seeder/migration
 *     run that edits tour rows should re-export via the manual command.
 *   - Coalesces within one request: if 5 observers fire on 5 saves in
 *     the same request, only ONE export runs at the terminating phase.
 *   - Cross-request contention uses a non-blocking cache lock. Losers
 *     log and skip — the next successful save will re-fire the pipeline.
 *   - Fail-soft: any failure inside the terminating callback is caught
 *     and logged. The admin save is never affected.
 */
class AutoExportScheduler
{
    /** Flag per request: once set, observers become no-ops. */
    private bool $scheduled = false;

    public function __construct(
        private Application $app,
        private TourCatalogExportService $exporter,
        private StaticSitePageCache $cache,
    ) {
    }

    /**
     * Called from model observers. Registers the after-response callback
     * the first time it's called in a request; subsequent calls are
     * no-ops (intra-request coalescing).
     */
    public function schedule(): void
    {
        if ($this->scheduled) {
            return;
        }

        // CLI contexts (artisan commands, tinker, seeders, migrations)
        // must NOT trigger auto-export. Operators running CLI work are
        // expected to re-export via `php artisan tours:export-website-data`
        // explicitly when they need the website to reflect their changes.
        if ($this->app->runningInConsole()) {
            return;
        }

        // Global kill switch. Defaults to enabled; set TOUR_AUTO_EXPORT=false
        // in .env to pause the auto-export pipeline without reverting code.
        if (! (bool) config('tour_export.auto_export_enabled', true)) {
            return;
        }

        $this->scheduled = true;

        $this->app->terminating(function (): void {
            $this->runExport();
        });
    }

    /**
     * After-response export runner. Non-blocking lock: if another
     * request is already exporting, we log and return. The other
     * request will see the same DB state (or newer) and its export
     * will cover our changes too.
     */
    public function runExport(): void
    {
        try {
            $lock = Cache::lock('tour-catalog-export', 10);

            if (! $lock->get()) {
                Log::info('Tour catalog auto-export skipped: lock held');

                return;
            }

            try {
                $result = $this->exporter->export();
                $this->cache->purge();

                Log::info('Tour catalog auto-exported', [
                    'tours'       => $result['tours_count'],
                    'bytes'       => $result['bytes'],
                    'target_path' => $result['target_path'],
                ]);
            } finally {
                optional($lock)->release();
            }
        } catch (Throwable $e) {
            // Swallow — admin save already succeeded.
            Log::error('Tour catalog auto-export failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }
}
