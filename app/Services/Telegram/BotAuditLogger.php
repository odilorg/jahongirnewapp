<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Enums\AccessAction;
use App\Enums\AccessResult;
use App\Models\TelegramBot;
use App\Models\TelegramBotAccessLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class BotAuditLogger implements BotAuditLoggerInterface
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {}

    public function log(
        ?TelegramBot $bot,
        AccessAction $action,
        AccessResult $result,
        ?string $serviceName = null,
        array $metadata = [],
    ): TelegramBotAccessLog {
        $actorUserId = null;
        $actorType = 'system';
        $actorIdentifier = $serviceName;

        // Resolve actor from request context if available
        if ($this->request !== null) {
            $user = $this->request->user();
            if ($user !== null) {
                $actorUserId = $user->id;
                $actorType = 'user';
                $actorIdentifier = $user->email ?? $user->name ?? (string) $user->id;
            }
        }

        // Detect CLI context
        if (app()->runningInConsole()) {
            $actorType = 'cli';
        }

        try {
            return TelegramBotAccessLog::create([
                'telegram_bot_id' => $bot?->id,
                'actor_user_id' => $actorUserId,
                'actor_type' => $actorType,
                'actor_identifier' => $actorIdentifier,
                'service_name' => $serviceName,
                'action' => $action,
                'result' => $result,
                'ip_address' => $this->request?->ip(),
                'user_agent' => $this->safeUserAgent(),
                'request_id' => $this->request?->header('X-Request-Id'),
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break the caller.
            // If the DB write fails, log to Laravel's logger as fallback.
            //
            // SANITIZATION GUARANTEE:
            // - Only bot_id (int), action (enum string), result (enum string)
            //   and the exception message are logged.
            // - No token values, webhook secrets, request headers/body,
            //   or caller-supplied metadata are written to the fallback log.
            Log::error('BotAuditLogger: failed to write access log', [
                'bot_id' => $bot?->id,
                'action' => $action->value,
                'result' => $result->value,
                'error' => $e->getMessage(),
            ]);

            // Return an unsaved model so callers don't NPE.
            // Intentionally omits metadata — caller-supplied data must not
            // leak through the fallback model if it gets serialized downstream.
            return new TelegramBotAccessLog([
                'telegram_bot_id' => $bot?->id,
                'action' => $action,
                'result' => $result,
            ]);
        }
    }

    public function logTokenAccess(TelegramBot $bot, string $serviceName): TelegramBotAccessLog
    {
        return $this->log(
            bot: $bot,
            action: AccessAction::TokenRead,
            result: AccessResult::Success,
            serviceName: $serviceName,
        );
    }

    public function logError(
        ?TelegramBot $bot,
        string $serviceName,
        string $errorCode,
        string $errorSummary,
    ): TelegramBotAccessLog {
        return $this->log(
            bot: $bot,
            action: AccessAction::Error,
            result: AccessResult::Error,
            serviceName: $serviceName,
            metadata: [
                'error_code' => $errorCode,
                'error_summary' => $errorSummary,
            ],
        );
    }

    /**
     * Truncate user-agent to prevent oversized DB writes from malicious headers.
     */
    private function safeUserAgent(): ?string
    {
        $ua = $this->request?->userAgent();

        if ($ua === null) {
            return null;
        }

        return mb_substr($ua, 0, 255);
    }
}
