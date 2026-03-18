<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use App\Enums\AccessAction;
use App\Enums\AccessResult;
use App\Models\TelegramBot;
use App\Models\TelegramBotAccessLog;

interface BotAuditLoggerInterface
{
    /**
     * Record an access log entry.
     *
     * @param TelegramBot|null $bot      Null for failed lookups (bot not found)
     * @param AccessAction     $action   What was attempted
     * @param AccessResult     $result   Whether it succeeded
     * @param string|null      $serviceName  FQCN of the calling service/controller
     * @param array            $metadata     Extra context (error codes, slugs, etc.)
     */
    public function log(
        ?TelegramBot $bot,
        AccessAction $action,
        AccessResult $result,
        ?string $serviceName = null,
        array $metadata = [],
    ): TelegramBotAccessLog;

    /**
     * Convenience: log a successful token read.
     */
    public function logTokenAccess(TelegramBot $bot, string $serviceName): TelegramBotAccessLog;

    /**
     * Convenience: log an error against a bot.
     */
    public function logError(
        ?TelegramBot $bot,
        string $serviceName,
        string $errorCode,
        string $errorSummary,
    ): TelegramBotAccessLog;
}
