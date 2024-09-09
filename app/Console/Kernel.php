<?php

namespace App\Console;

use Carbon\Carbon;
use App\Models\ScheduledMessage;
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
         // Fetch all scheduled messages from the database
         $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

         foreach ($scheduledMessages as $message) {
             // Calculate 24 hours before the scheduled time
             $runAt = Carbon::parse($message->scheduled_at)->subDay();
 
             // Schedule the job to run 24 hours before the actual scheduled time
             $schedule->call(function () use ($message) {
                 // Dispatch the job to send the Telegram message
                 SendTelegramMessageJob::dispatch($message);
             })
             ->timezone('Your/Timezone') // Ensure to set your timezone
             ->at($runAt->format('H:i')) // Schedule at the calculated time 24 hours before
             ->when(function () use ($runAt) {
                 // Check if the current time is the same day and time as 24 hours before
                 return now()->isSameDay($runAt);
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
