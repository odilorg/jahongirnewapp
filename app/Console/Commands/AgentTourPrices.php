<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\TourCatalogReader;
use Illuminate\Console\Command;

/**
 * Tour-agent price reader (Phase 2, read-only): full tier breakdown for one
 * tour, so the agent can explain pricing/options honestly. No writes.
 *
 *   php artisan agent:tour-prices yurt-camp-tour
 */
class AgentTourPrices extends Command
{
    protected $signature = 'agent:tour-prices {slug : Tour slug} {--compact : Single-line JSON}';

    protected $description = 'Read-only: price tiers for one tour (per-person + group totals, directions).';

    public function handle(TourCatalogReader $reader): int
    {
        $slug = (string) $this->argument('slug');
        $result = $reader->prices($slug);

        if ($result === null) {
            $this->line((string) json_encode(['ok' => false, 'error' => "tour not found: {$slug}"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
        $this->line((string) json_encode($result, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
