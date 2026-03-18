<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotResolverInterface;
use App\DTOs\ResolvedTelegramBot;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;

/**
 * Temporary fallback adapter that resolves bot tokens from config/services.php.
 *
 * ## Purpose
 *
 * During the migration period, some bots may not yet have rows in
 * telegram_bots + telegram_bot_secrets. This adapter reads the legacy
 * config entries and wraps them in a ResolvedTelegramBot DTO so that
 * consumers can use a single interface regardless of storage backend.
 *
 * ## Lifecycle
 *
 * This adapter is intended to be removed once all bots are seeded into
 * the database and consumers are migrated. It should NOT be used as a
 * permanent solution.
 *
 * ## Slug → config key mapping
 *
 * The slug names are chosen to match what will be used in the database:
 *
 *   owner-alert     → services.owner_alert_bot.token
 *   driver-guide    → services.driver_guide_bot.token
 *   pos             → services.telegram_pos_bot.token
 *   booking         → services.telegram_booking_bot.token
 *   cashier         → services.cashier_bot.token
 *   housekeeping    → services.housekeeping_bot.token
 *   kitchen         → services.kitchen_bot.token
 *   main            → services.telegram.bot_token
 */
final class LegacyConfigBotAdapter
{
    /**
     * Slug → [config_key_for_token, config_key_for_webhook_secret|null, display_name]
     */
    private const SLUG_MAP = [
        'owner-alert' => [
            'token' => 'services.owner_alert_bot.token',
            'webhook_secret' => null,
            'name' => 'Owner Alert Bot',
        ],
        'driver-guide' => [
            'token' => 'services.driver_guide_bot.token',
            'webhook_secret' => 'services.driver_guide_bot.webhook_secret',
            'name' => 'Driver & Guide Bot',
        ],
        'pos' => [
            'token' => 'services.telegram_pos_bot.token',
            'webhook_secret' => 'services.telegram_pos_bot.secret_token',
            'name' => 'POS Bot',
        ],
        'booking' => [
            'token' => 'services.telegram_booking_bot.token',
            'webhook_secret' => 'services.telegram_booking_bot.secret_token',
            'name' => 'Booking Bot',
        ],
        'cashier' => [
            'token' => 'services.cashier_bot.token',
            'webhook_secret' => 'services.cashier_bot.webhook_secret',
            'name' => 'Cashier Bot',
        ],
        'housekeeping' => [
            'token' => 'services.housekeeping_bot.token',
            'webhook_secret' => null,
            'name' => 'Housekeeping Bot',
        ],
        'kitchen' => [
            'token' => 'services.kitchen_bot.token',
            'webhook_secret' => null,
            'name' => 'Kitchen Bot',
        ],
        'main' => [
            'token' => 'services.telegram.bot_token',
            'webhook_secret' => null,
            'name' => 'Main Telegram Bot',
        ],
    ];

    /**
     * Resolve a bot from legacy config.
     *
     * @throws BotNotFoundException         if slug is not in the legacy map
     * @throws BotSecretUnavailableException if config value is empty/missing
     */
    public function resolve(string $slug): ResolvedTelegramBot
    {
        if (! isset(self::SLUG_MAP[$slug])) {
            throw new BotNotFoundException($slug);
        }

        $mapping = self::SLUG_MAP[$slug];
        $token = config($mapping['token']);

        if (empty($token)) {
            throw new BotSecretUnavailableException($slug);
        }

        $webhookSecret = null;
        if ($mapping['webhook_secret'] !== null) {
            $ws = config($mapping['webhook_secret']);
            $webhookSecret = ! empty($ws) ? $ws : null;
        }

        return new ResolvedTelegramBot(
            botId: null, // No database row — use slug + source for identification
            slug: $slug,
            name: $mapping['name'],
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::fromAppEnvironment((string) app()->environment()),
            token: $token,
            webhookSecret: $webhookSecret,
            secretVersion: 0,
            source: 'legacy_config',
        );
    }

    /**
     * Resolve without throwing — returns null on any failure.
     */
    public function tryResolve(string $slug): ?ResolvedTelegramBot
    {
        try {
            return $this->resolve($slug);
        } catch (BotNotFoundException | BotSecretUnavailableException) {
            return null;
        }
    }

    /**
     * Returns all slug names known to the legacy adapter.
     *
     * @return list<string>
     */
    public static function knownSlugs(): array
    {
        return array_keys(self::SLUG_MAP);
    }

}
