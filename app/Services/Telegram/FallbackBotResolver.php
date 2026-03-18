<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotResolverInterface;
use App\DTOs\ResolvedTelegramBot;
use App\Exceptions\Telegram\BotNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Composite resolver: tries the database-backed BotResolver first,
 * falls back to LegacyConfigBotAdapter during the migration period.
 *
 * ## Lifecycle
 *
 * Once all bots are seeded into the database and the legacy config
 * entries are removed, this class should be replaced by binding
 * BotResolverInterface directly to BotResolver.
 *
 * ## Fallback behavior
 *
 * - If the database resolver succeeds → returns database DTO (source='database').
 * - If the database resolver throws BotNotFoundException → tries legacy adapter.
 * - If both fail → throws BotNotFoundException from the database resolver.
 * - Non-BotNotFoundException errors (disabled, env mismatch, secret missing)
 *   are NOT caught — they propagate immediately because the bot exists in the
 *   database but has a real problem that the legacy adapter shouldn't mask.
 */
final class FallbackBotResolver implements BotResolverInterface
{
    public function __construct(
        private readonly BotResolver $databaseResolver,
        private readonly LegacyConfigBotAdapter $legacyAdapter,
    ) {}

    public function resolve(string $slug): ResolvedTelegramBot
    {
        try {
            return $this->databaseResolver->resolve($slug);
        } catch (BotNotFoundException $dbException) {
            // Bot not in database — try legacy config
            $legacy = $this->legacyAdapter->tryResolve($slug);

            if ($legacy !== null) {
                Log::info("BotResolver: [{$slug}] resolved via legacy config fallback. Migrate to database.", [
                    'slug' => $slug,
                ]);

                return $legacy;
            }

            // Neither source has it — throw the original database exception
            throw $dbException;
        }
    }

    public function tryResolve(string $slug): ?ResolvedTelegramBot
    {
        try {
            return $this->resolve($slug);
        } catch (\Throwable) {
            return null;
        }
    }
}
