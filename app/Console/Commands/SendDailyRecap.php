<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailyRecapBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 22 — send the operator's daily recap via Telegram at 19:00 Tashkent.
 *
 * Uses the same ops_bot channel already configured for new-inquiry alerts.
 * Runs dailyAt('19:00')->timezone('Asia/Tashkent') from Kernel.
 *
 * Flags:
 *   --dry-run      print message to console, don't send
 *   --user=<id>    only include that user's assigned bookings
 */
class SendDailyRecap extends Command
{
    protected $signature   = 'recap:send-daily {--dry-run} {--user=}';
    protected $description = 'Send daily evening recap of tomorrows tours via Telegram';

    public function handle(DailyRecapBuilder $builder): int
    {
        $assignedUserId = $this->option('user') ? (int) $this->option('user') : null;
        $data    = $builder->buildForTomorrow($assignedUserId);
        $base    = rtrim(config('app.url', ''), '/');
        $message = $builder->formatTelegram($data, $base);

        if ($this->option('dry-run')) {
            $this->line('--- DRY RUN ---');
            $this->line($message);
            $this->line('--- END ---');
            return self::SUCCESS;
        }

        $token  = (string) config('services.ops_bot.token');
        $chatId = (string) config('services.ops_bot.owner_chat_id');

        if ($token === '' || $chatId === '') {
            $this->error('ops_bot not configured — set TELEGRAM_OWNER_CHAT_ID + ops_bot token in .env');
            Log::warning('recap:send-daily: ops_bot not configured');
            return self::FAILURE;
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $message,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful()) {
            Log::warning('recap:send-daily: Telegram API non-2xx', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 300),
            ]);
            $this->error("Failed to send — HTTP {$response->status()}");
            return self::FAILURE;
        }

        $this->info('Recap sent: ' . strlen($message) . ' chars, ' . $data['total_bookings'] . ' bookings');
        Log::info('recap:send-daily: sent', [
            'tomorrow'       => $data['date'],
            'total_bookings' => $data['total_bookings'],
            'needs_action'   => count($data['needs_action']),
        ]);

        return self::SUCCESS;
    }
}
