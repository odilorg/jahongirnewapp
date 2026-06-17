<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TourProduct;
use App\Services\Agent\TourCatalogReader;
use Illuminate\Console\Command;

/**
 * Tour-agent quoting guardrail (Phase 2, read-only): resolve a price for a
 * hypothetical {tour, party, direction, type} with NO inquiry needed.
 *
 * Reuses TourProduct::priceFor() (single source of truth). An unresolved quote
 * returns resolvable=false / manual_quote_needed with a reason — it NEVER
 * invents a price. Group + private-only tiers and no-tier custom tours both
 * resolve to manual_quote_needed.
 *
 *   php artisan agent:quote-calculate --tour=yurt-camp-tour --party=2
 *   php artisan agent:quote-calculate --tour=daytrip-shahrisabz --party=2 --type=group
 */
class AgentQuoteCalculate extends Command
{
    protected $signature = 'agent:quote-calculate
                            {--tour= : Tour slug}
                            {--party= : Party size (adults + children)}
                            {--direction= : Direction code (defaults to "default")}
                            {--type=private : private|group}
                            {--compact : Single-line JSON}';

    protected $description = 'Read-only: resolve a tour price for a party, or manual_quote_needed. Never guesses.';

    public function handle(TourCatalogReader $reader): int
    {
        $slug = (string) $this->option('tour');
        if ($slug === '') {
            return $this->emit(['ok' => false, 'error' => '--tour is required']);
        }

        $partyOpt = (string) $this->option('party');
        if ($partyOpt === '' || ! ctype_digit($partyOpt)) {
            return $this->emit(['ok' => false, 'error' => '--party must be a positive integer']);
        }

        $result = $reader->quote(
            $slug,
            (int) $partyOpt,
            (string) ($this->option('direction') ?: 'default'),
            (string) ($this->option('type') ?: TourProduct::TYPE_PRIVATE),
        );

        return $this->emit($result);
    }

    /** @param array<string,mixed> $data */
    private function emit(array $data): int
    {
        $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
        $this->line((string) json_encode($data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
