<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Telegram\BotResolverInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Telegram API request via the queue.
 *
 * ## Security
 *
 * This job stores only the bot SLUG, never the token. The token is resolved
 * from the container at handle()-time via BotResolverInterface, so it never
 * appears in queue payloads (Redis, database, SQS, etc.).
 *
 * The previous version accepted $botToken as a constructor parameter, which
 * serialized the plaintext token into every queue payload.
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;

    public function __construct(
        public string $botSlug,
        public string $method,
        public array $params,
    ) {
        $this->onQueue('telegram');
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(BotResolverInterface $resolver): void
    {
        $bot = $resolver->resolve($this->botSlug);
        $url = "https://api.telegram.org/bot{$bot->token}/{$this->method}";

        $resp = Http::timeout(15)->post($url, $this->params);

        if (! $resp->successful()) {
            $body = $resp->body();
            $status = $resp->status();

            // Telegram 429 = rate limited, retry
            if ($status === 429) {
                $retryAfter = $resp->json('parameters.retry_after', 30);
                Log::warning('Telegram rate limited', [
                    'method' => $this->method,
                    'chat_id' => $this->params['chat_id'] ?? null,
                    'retry_after' => $retryAfter,
                ]);
                $this->release($retryAfter);

                return;
            }

            // Telegram 400 with "chat not found" or "bot blocked" — don't retry
            if ($status === 400 || $status === 403) {
                Log::warning('Telegram permanent error, skipping', [
                    'method' => $this->method,
                    'chat_id' => $this->params['chat_id'] ?? null,
                    'status' => $status,
                    'body' => $body,
                ]);

                return;
            }

            // Other errors — throw to trigger retry
            Log::error('Telegram API error', [
                'method' => $this->method,
                'chat_id' => $this->params['chat_id'] ?? null,
                'status' => $status,
                'body' => $body,
            ]);

            throw new \RuntimeException("Telegram API error: {$status} {$body}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::critical('Telegram notification permanently failed', [
            'bot_slug' => $this->botSlug,
            'method' => $this->method,
            'chat_id' => $this->params['chat_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
