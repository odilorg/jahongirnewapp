<?php

namespace App\Console;

use App\Jobs\SendTelegramMessageJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon; // Correct Carbon import
use App\Models\ScheduledMessage; // Import your ScheduledMessage model

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Fetch all scheduled messages that are due to be scheduled soon
        $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

        foreach ($scheduledMessages as $message) {
            // Convert scheduled_at to a Carbon instance and calculate 24 hours before
            $runAt = Carbon::parse($message->scheduled_at);

            // Determine the frequency method
            $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

            // Schedule the job according to the frequency and calculated time
            $schedule->call(function () use ($message) {
                SendTelegramMessageJob::dispatch($message);
            })
            ->timezone('Asia/Samarkand')
            ->{$frequencyMethod}($runAt->day, $runAt->format('H:i'));
        }
    }

    /**
     * Map frequency to Laravel scheduler methods.
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

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
