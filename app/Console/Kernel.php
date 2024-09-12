<?php

// app/Console/Kernel.php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon;
use App\Models\ScheduledMessage;
use App\Models\Chat;
use App\Jobs\SendTelegramMessageJob;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

        foreach ($scheduledMessages as $message) {
            $runAt = Carbon::parse($message->scheduled_at);

            $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

            // Get the related chat from the Chat model
            $chat = $message->chat;
//dd($chat);
            if ($chat) {
                $schedule->call(function () use ($message) {
                    SendTelegramMessageJob::dispatch($message);
                })
                ->timezone('Asia/Samarkand');
                
                // Use the correct frequency method with proper arguments
                // if ($frequencyMethod === 'dailyAt') {
                //     $schedule->dailyAt($runAt->format('H:i'));
                // } elseif ($frequencyMethod === 'weeklyOn') {
                //     $schedule->weeklyOn($runAt->dayOfWeek, $runAt->format('H:i'));
                // } elseif ($frequencyMethod === 'monthlyOn') {
                //     $schedule->monthlyOn($runAt->day, $runAt->format('H:i'));
                // } elseif ($frequencyMethod === 'yearlyOn') {
                //     $schedule->yearlyOn($runAt->month, $runAt->day, $runAt->format('H:i'));
                // }
            }
        }
    }

    protected function mapFrequencyToMethod($frequency)
    {
        switch ($frequency) {
            case 'daily':
                return 'dailyAt';
            case 'weekly':
                return 'weeklyOn';
            case 'monthly':
                return 'monthlyOn';
            case 'yearly':
                return 'yearlyOn';
            default:
                return 'dailyAt';
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
