<?php

namespace App\Console;

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon; // Don't forget to import Carbon
use App\Models\ScheduledMessage; // Import your ScheduledMessage model

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Fetch all scheduled messages from the database
        $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

        foreach ($scheduledMessages as $message) {
            // Convert scheduled_at to Carbon instance
            $runAt = Carbon::parse($message->scheduled_at); // 24 hours before the scheduled time

            // Determine the frequency method
            $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

            // Schedule the job to run 24 hours before the actual scheduled time
            $schedule->call(function () use ($message) {
                // Dispatch the job to send the Telegram message
                SendTelegramMessageJob::dispatch($message);
            })
                ->timezone('Asia/Samarkand')
                ->at($runAt->format('H:i')) // Use Carbon instance to format the time
                ->when(function () use ($runAt) {
                    // Check if the current time is the same day and time as 24 hours before
                    return now()->isSameDay($runAt);
                })
                ->{$frequencyMethod}($runAt->day, $runAt->format('H:i'));
        }
    }

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

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
