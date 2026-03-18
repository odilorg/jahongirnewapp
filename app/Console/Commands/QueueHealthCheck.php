<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueueHealthCheck extends Command
{
    protected $signature = 'queue:health-check';
    protected $description = 'Alert if queue jobs are stuck or Telegram bot webhooks have errors';

    public function handle(): int
    {
        $issues = [];

        // 1. Check Telegram bot webhooks for errors / stuck pending updates
        $this->checkBotWebhooks($issues);

        // 2. Check queue jobs stuck >10 min
        $stuckThreshold = now()->subMinutes(10);
        $stuckCount = DB::table('jobs')
            ->where('created_at', '<', $stuckThreshold->timestamp)
            ->count();

        if ($stuckCount === 0 && empty($issues)) {
            return 0;
        }

        $msg = '';

        if (!empty($issues)) {
            $msg .= "🔴 <b>Bot Webhook Issues</b>\n\n";
            foreach ($issues as $issue) {
                $msg .= $issue . "\n";
            }
            $msg .= "\n";
        }

        if ($stuckCount > 0) {
            $stuckJobs = DB::table('jobs')
                ->where('created_at', '<', $stuckThreshold->timestamp)
                ->get(['id', 'queue', 'payload', 'attempts', 'created_at']);

            $queues = $stuckJobs->groupBy('queue')->map->count();
            $oldest = $stuckJobs->min('created_at');
            $oldestAge = now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($oldest));

            $msg .= "🔴 <b>Queue: {$stuckCount} stuck jobs</b>\n"
                . "⏱ Oldest: {$oldestAge} min ago\n";
            foreach ($queues as $queue => $count) {
                $msg .= "📋 {$queue}: {$count}\n";
            }
            $msg .= "Fix: <code>pm2 restart hotel-queue</code>\n";
        }

        Log::error('Health check: issues detected', [
            'stuck_jobs' => $stuckCount,
            'webhook_issues' => count($issues),
        ]);

        $botToken = config('services.owner_alert_bot.token', env('OWNER_ALERT_BOT_TOKEN'));
        $chatId = config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '38738713'));

        if ($botToken && $chatId && $msg) {
            try {
                Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $msg,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                Log::error('Health check: failed to send alert', ['error' => $e->getMessage()]);
            }
        }

        $this->error('Health check failed');
        return 1;
    }

    private function checkBotWebhooks(array &$issues): void
    {
        $bots = [
            'cashier'      => config('services.cashier_bot.token', ''),
            'housekeeping' => config('services.housekeeping_bot.token', ''),
            'kitchen'      => config('services.kitchen_bot.token', ''),
            'driver'       => config('services.driver_guide_bot.token', ''),
        ];

        foreach ($bots as $name => $token) {
            if (empty($token)) continue;

            try {
                $resp = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getWebhookInfo");
                $info = $resp->json('result');
                if (!$info) continue;

                $pending = $info['pending_update_count'] ?? 0;
                $lastError = $info['last_error_message'] ?? '';
                $lastErrorDate = $info['last_error_date'] ?? 0;
                $url = $info['url'] ?? '';

                $errorIsRecent = $lastErrorDate > 0 && (time() - $lastErrorDate) < 600;

                if ($pending > 3 && $errorIsRecent) {
                    $issues[] = "⚠️ <b>{$name}</b>: {$pending} stuck, error: {$lastError}";

                    Http::timeout(5)->get("https://api.telegram.org/bot{$token}/deleteWebhook", [
                        'drop_pending_updates' => true,
                    ]);
                    if ($url) {
                        Http::timeout(5)->get("https://api.telegram.org/bot{$token}/setWebhook", [
                            'url' => $url,
                        ]);
                    }
                    $issues[] = "🔄 <b>{$name}</b>: auto-recovered";
                    Log::warning("Health check: auto-recovered {$name} bot webhook", [
                        'pending' => $pending, 'error' => $lastError, 'url' => $url,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Health check: failed to check {$name} bot", ['error' => $e->getMessage()]);
            }
        }
    }
}
