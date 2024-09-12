<?php

// app/Console/Kernel.php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon;
use App\Models\ScheduledMessage;
use App\Models\Chat;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

        foreach ($scheduledMessages as $message) {
            // Convert scheduled_at to Carbon instance
            $runAt = Carbon::parse($message->scheduled_at);

            // Determine the frequency method
            $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

            // Get the related chat from the Chat model
            $chat = $message->chat;

            if ($chat) {
                // Schedule the job dynamically based on frequency and chat_id
                $schedule->call(function () use ($message, $chat) {
                    // Dispatch the job to send the Telegram message with dynamic chat ID
                    \App\Jobs\SendTelegramMessageJob::dispatch($message, $chat->chat_id);
                })
                ->timezone('Asia/Samarkand') // Set your timezone
                ->{$frequencyMethod}($runAt->day, $runAt->format('H:i'));
            }
        }
    }

    /**
     * Map frequency to the appropriate Laravel Scheduler method.
     *
     * @param string $frequency
     * @return string
     */
    protected function mapFrequencyToMethod($frequency)
    {
        switch ($frequency) {
            case 'daily':
                return 'dailyAt'; // Schedule job daily at a specific time
            case 'weekly':
                return 'weeklyOn'; // Schedule job weekly on a specific day and time
            case 'monthly':
                return 'monthlyOn'; // Schedule job monthly on a specific date and time
            case 'yearly':
                return 'yearlyOn'; // Schedule job yearly on a specific date and time
            default:
                return 'dailyAt'; // Default fallback to daily
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
