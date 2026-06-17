<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agent\TourCatalogReader;
use Illuminate\Console\Command;

/**
 * Tour-agent catalog reader (Phase 2, read-only): the whole active product
 * line (or one tour by slug), so the agent understands what Jahongir sells
 * without depending on a single inquiry. No writes, no PII.
 *
 *   php artisan agent:tour-catalog
 *   php artisan agent:tour-catalog --slug=yurt-camp-tour --compact
 */
class AgentTourCatalog extends Command
{
    protected $signature = 'agent:tour-catalog {--slug= : Limit to one tour slug} {--compact : Single-line JSON}';

    protected $description = 'Read-only: active tour catalog (slug, region, duration, tiers?, inclusions/exclusions, manual_quote flag).';

    public function handle(TourCatalogReader $reader): int
    {
        $slug = (string) $this->option('slug');
        $result = $reader->catalog($slug !== '' ? $slug : null);

        $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
        $this->line((string) json_encode($result, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
