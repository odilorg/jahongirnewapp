<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueueHealthCheck extends Command
{
    protected $signature = 'queue:health-check';
    protected $description = 'Alert if queue jobs are stuck (not processed for 10+ minutes)';

    public function handle(): int
    {
        $stuckThreshold = now()->subMinutes(10);

        // Count jobs older than 10 minutes (stuck)
        $stuckCount = DB::table('jobs')
            ->where('created_at', '<', $stuckThreshold->timestamp)
            ->count();

        if ($stuckCount === 0) {
            return 0;
        }

        // Get details of stuck jobs
        $stuckJobs = DB::table('jobs')
            ->where('created_at', '<', $stuckThreshold->timestamp)
            ->get(['id', 'queue', 'payload', 'attempts', 'created_at']);

        $queues = $stuckJobs->groupBy('queue')->map->count();
        $oldest = $stuckJobs->min('created_at');
        $oldestAge = now()->diffInMinutes(\Carbon\Carbon::createFromTimestamp($oldest));

        $msg = "🔴 <b>Queue Alert: {$stuckCount} stuck jobs!</b>\n\n"
            . "⏱ Oldest: {$oldestAge} min ago\n";

        foreach ($queues as $queue => $count) {
            $msg .= "📋 {$queue}: {$count} jobs\n";
        }

        $msg .= "\n⚠️ Queue worker may be down or using stale config.\n"
            . "Fix: <code>pm2 restart hotel-queue</code>";

        Log::error('Queue health check: stuck jobs detected', [
            'stuck_count' => $stuckCount,
            'oldest_minutes' => $oldestAge,
            'queues' => $queues->toArray(),
        ]);

        // Send Telegram alert to owner
        $botToken = config('services.owner_alert_bot.token', env('OWNER_ALERT_BOT_TOKEN'));
        $chatId = config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '38738713'));

        if ($botToken && $chatId) {
            try {
                Http::timeout(10)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $msg,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                Log::error('Queue health check: failed to send alert', ['error' => $e->getMessage()]);
            }
        }

        $this->error("⚠️ {$stuckCount} stuck jobs (oldest: {$oldestAge}min)");
        return 1;
    }
}
