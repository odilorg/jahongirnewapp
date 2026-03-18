<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\BotSecretProviderInterface;
use App\DTOs\ResolvedTelegramBot;
use App\Enums\AccessAction;
use App\Enums\AccessResult;
use App\Enums\BotEnvironment;
use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotEnvironmentMismatchException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Models\TelegramBot;

/**
 * Resolves a bot slug into a fully hydrated ResolvedTelegramBot DTO.
 *
 * Enforcement order:
 * 1. Exists
 * 2. Usable (status = active)
 * 3. Environment match
 * 4. Active secret exists and decrypts
 *
 * Each failure is audit-logged before throwing.
 */
final class BotResolver implements BotResolverInterface
{
    public function __construct(
        private readonly BotSecretProviderInterface $secretProvider,
        private readonly BotAuditLoggerInterface $auditLogger,
    ) {}

    public function resolve(string $slug): ResolvedTelegramBot
    {
        // 1. Exists?
        $bot = TelegramBot::query()->bySlug($slug)->first();

        if ($bot === null) {
            $this->auditLogger->log(
                bot: null,
                action: AccessAction::Error,
                result: AccessResult::NotFound,
                serviceName: self::class,
                metadata: ['slug' => $slug],
            );

            throw new BotNotFoundException($slug);
        }

        // 2. Usable?
        if (! $bot->isUsable()) {
            $this->auditLogger->log(
                bot: $bot,
                action: AccessAction::Error,
                result: AccessResult::Denied,
                serviceName: self::class,
                metadata: ['reason' => 'disabled', 'status' => $bot->status->value],
            );

            throw new BotDisabledException($slug, $bot->status);
        }

        // 3. Environment match?
        $this->enforceEnvironment($bot);

        // 4. Active secret?
        $token = $this->secretProvider->getActiveToken($bot);
        $webhookSecret = $this->secretProvider->getActiveWebhookSecret($bot);
        $secretVersion = $this->secretProvider->getActiveSecretVersion($bot);

        // Audit the successful resolution
        $this->auditLogger->logTokenAccess($bot, self::class);

        // Touch last_used_at
        $bot->markUsed();

        return new ResolvedTelegramBot(
            botId: $bot->id,
            slug: $bot->slug,
            name: $bot->name,
            botUsername: $bot->bot_username,
            status: $bot->status,
            environment: $bot->environment,
            token: $token,
            webhookSecret: $webhookSecret,
            secretVersion: $secretVersion,
            source: 'database',
        );
    }

    public function tryResolve(string $slug): ?ResolvedTelegramBot
    {
        try {
            return $this->resolve($slug);
        } catch (BotNotFoundException | BotDisabledException | BotEnvironmentMismatchException | BotSecretUnavailableException) {
            return null;
        }
    }

    /**
     * Enforce that the bot's registered environment matches the running app.
     *
     * Mapping: app('env') → BotEnvironment
     *   'production'         → Production
     *   'staging'            → Staging
     *   'local', 'testing'   → Development
     *
     * @throws BotEnvironmentMismatchException
     */
    private function enforceEnvironment(TelegramBot $bot): void
    {
        $appEnv = (string) app()->environment();
        $expected = $this->mapAppEnvironment($appEnv);

        if ($bot->environment !== $expected) {
            $this->auditLogger->log(
                bot: $bot,
                action: AccessAction::Error,
                result: AccessResult::Denied,
                serviceName: self::class,
                metadata: [
                    'reason' => 'environment_mismatch',
                    'bot_env' => $bot->environment->value,
                    'app_env' => $appEnv,
                ],
            );

            throw new BotEnvironmentMismatchException($bot->slug, $bot->environment, $appEnv);
        }
    }

    private function mapAppEnvironment(string $appEnv): BotEnvironment
    {
        return match ($appEnv) {
            'production' => BotEnvironment::Production,
            'staging' => BotEnvironment::Staging,
            default => BotEnvironment::Development,
        };
    }
}
