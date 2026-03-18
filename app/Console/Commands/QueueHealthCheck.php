<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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

        $this->checkBotWebhooks($issues);

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

        $botToken = config('services.owner_alert_bot.token');
        $chatId = config('services.owner_alert_bot.owner_chat_id', '38738713');

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

    /**
     * Check all bot webhooks. Uses a 2-strike policy:
     * - First bad check: alert only, mark unhealthy in cache
     * - Second consecutive bad check: auto-recover (re-register, drop pending only if queue growing)
     * - Cooldown: max 1 auto-recovery per bot per 30 minutes
     */
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
                $isUnhealthy = $pending > 3 && $errorIsRecent;

                $cacheKey = "bot_health:{$name}";
                $cooldownKey = "bot_recovery_cooldown:{$name}";
                $prevState = Cache::get($cacheKey);

                if (!$isUnhealthy) {
                    // Healthy — clear state
                    Cache::forget($cacheKey);
                    continue;
                }

                // Unhealthy — check if this is first or second consecutive detection
                if (!$prevState) {
                    // First strike: alert only, record state
                    Cache::put($cacheKey, [
                        'pending' => $pending,
                        'error' => $lastError,
                        'detected_at' => now()->timestamp,
                    ], now()->addMinutes(15));

                    $issues[] = "⚠️ <b>{$name}</b>: {$pending} pending, error: {$lastError} (monitoring)";
                    Log::warning("Health check: {$name} bot unhealthy (strike 1)", [
                        'pending' => $pending, 'error' => $lastError,
                    ]);
                    continue;
                }

                // Second strike — check cooldown
                if (Cache::has($cooldownKey)) {
                    $issues[] = "⏳ <b>{$name}</b>: still unhealthy, recovery on cooldown";
                    continue;
                }

                // Second strike, no cooldown — auto-recover
                $prevPending = $prevState['pending'] ?? 0;
                $queueGrowing = $pending >= $prevPending;

                // Step 1: Try re-register without dropping (if queue is draining)
                if (!$queueGrowing && $url) {
                    Http::timeout(5)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                        'url' => $url,
                    ]);
                    $issues[] = "🔄 <b>{$name}</b>: re-registered webhook (queue draining, kept pending)";
                } else {
                    // Step 2: Queue growing or stuck — drop pending + re-register
                    Http::timeout(5)->get("https://api.telegram.org/bot{$token}/deleteWebhook", [
                        'drop_pending_updates' => true,
                    ]);
                    if ($url) {
                        Http::timeout(5)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                            'url' => $url,
                        ]);
                    }
                    $issues[] = "🔴 <b>{$name}</b>: reset webhook + dropped {$pending} pending updates";
                }

                // Set cooldown (30 min) and clear health state
                Cache::put($cooldownKey, true, now()->addMinutes(30));
                Cache::forget($cacheKey);

                Log::warning("Health check: auto-recovered {$name} bot", [
                    'pending' => $pending,
                    'prev_pending' => $prevPending,
                    'queue_growing' => $queueGrowing,
                    'error' => $lastError,
                    'url' => $url,
                    'action' => $queueGrowing ? 'drop+reset' : 'refresh',
                ]);
            } catch (\Throwable $e) {
                Log::warning("Health check: failed to check {$name} bot", ['error' => $e->getMessage()]);
            }
        }
    }
}
