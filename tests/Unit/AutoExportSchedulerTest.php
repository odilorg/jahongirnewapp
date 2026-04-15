<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AutoExportScheduler;
use App\Services\StaticSitePageCache;
use App\Services\TourCatalogExportService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AutoExportSchedulerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeScheduler(
        bool $runningInConsole = false,
        ?TourCatalogExportService $exporter = null,
        ?StaticSitePageCache $cache = null,
    ): AutoExportScheduler {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn($runningInConsole);
        $app->shouldReceive('terminating')->andReturnUsing(function ($cb) {
            // Don't actually run terminating during test — schedule() only
            // needs to prove the callback was accepted. runExport() is
            // tested separately.
        });

        $exporter ??= Mockery::mock(TourCatalogExportService::class);
        $cache    ??= Mockery::mock(StaticSitePageCache::class);

        return new AutoExportScheduler($app, $exporter, $cache);
    }

    public function test_schedule_is_a_noop_in_cli_context(): void
    {
        config(['tour_export.auto_export_enabled' => true]);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn(true);
        $app->shouldNotReceive('terminating'); // must NOT register anything

        $scheduler = new AutoExportScheduler(
            $app,
            Mockery::mock(TourCatalogExportService::class),
            Mockery::mock(StaticSitePageCache::class),
        );

        $scheduler->schedule();
        $this->assertTrue(true); // reaching here means terminating() was not called
    }

    public function test_schedule_is_a_noop_when_auto_export_disabled(): void
    {
        config(['tour_export.auto_export_enabled' => false]);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn(false);
        $app->shouldNotReceive('terminating');

        $scheduler = new AutoExportScheduler(
            $app,
            Mockery::mock(TourCatalogExportService::class),
            Mockery::mock(StaticSitePageCache::class),
        );

        $scheduler->schedule();
        $this->assertTrue(true);
    }

    public function test_schedule_registers_terminating_exactly_once_per_request(): void
    {
        config(['tour_export.auto_export_enabled' => true]);

        $terminatingCalls = 0;
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('runningInConsole')->andReturn(false);
        $app->shouldReceive('terminating')->andReturnUsing(function () use (&$terminatingCalls) {
            $terminatingCalls++;
        });

        $scheduler = new AutoExportScheduler(
            $app,
            Mockery::mock(TourCatalogExportService::class),
            Mockery::mock(StaticSitePageCache::class),
        );

        // Simulate 5 observer fires in one request.
        for ($i = 0; $i < 5; $i++) {
            $scheduler->schedule();
        }

        $this->assertSame(1, $terminatingCalls);
    }

    public function test_run_export_calls_service_and_purges_cache_on_success(): void
    {
        $exporter = Mockery::mock(TourCatalogExportService::class);
        $exporter->shouldReceive('export')->once()->andReturn([
            'tours_count'  => 12,
            'bytes'        => 9993,
            'generated_at' => '2026-04-15T22:00:00+05:00',
            'target_path'  => '/domains/jahongir-travel.uz/data/tours.php',
            'pushed'       => true,
            'skipped'      => [],
        ]);

        $cache = Mockery::mock(StaticSitePageCache::class);
        $cache->shouldReceive('purge')->once()->andReturn(true);

        $scheduler = $this->makeScheduler(exporter: $exporter, cache: $cache);
        $scheduler->runExport();

        $this->assertTrue(true);
    }

    public function test_run_export_swallows_exceptions_silently(): void
    {
        $exporter = Mockery::mock(TourCatalogExportService::class);
        $exporter->shouldReceive('export')->andThrow(new \RuntimeException('simulated failure'));

        $cache = Mockery::mock(StaticSitePageCache::class);
        $cache->shouldNotReceive('purge');

        $scheduler = $this->makeScheduler(exporter: $exporter, cache: $cache);

        // Must not throw — caller (terminating callback) never sees exceptions.
        $scheduler->runExport();

        $this->assertTrue(true);
    }
}
