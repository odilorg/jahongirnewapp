<?php

namespace App\Console;

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        // Fetch scheduled messages from the database
    $scheduledMessages = \App\Models\ScheduledMessage::where('scheduled_at', '>=', now())->get();

    foreach ($scheduledMessages as $message) {
        $schedule->call(function () use ($message) {
            // Your logic to send the Telegram message
            SendTelegramMessageJob::dispatch($message);
        })->timezone('Asia/Samarkand')->at($message->scheduled_at->format('H:i'))->when(function () use ($message) {
            return now()->isSameDay($message->scheduled_at);
        });
    }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
