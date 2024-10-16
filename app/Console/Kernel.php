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

            // Initialize the frequency method based on the message's frequency
            $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

            // Get the related chat from the Chat model
            $chat = $message->chat->chat_id;

            if ($chat) {
                $schedule->call(function () use ($message, $chat) {
                    // Pass both $message and $chat->chat_id to the job
                    SendTelegramMessageJob::dispatch($message, $chat);
                
                })
                ->timezone('Asia/Samarkand')
                ->at($runAt->format('H:i'))
                ->when(function () use ($message) {
                    return now()->isSameDay($message->scheduled_at);
                });

                // Dynamically call the appropriate frequency method with the correct parameters
                // switch ($frequencyMethod) {
                //     case 'dailyAt':
                //         $schedule->dailyAt($runAt->format('H:i'));
                //         break;
                //     case 'weeklyOn':
                //         $schedule->weeklyOn($runAt->dayOfWeek, $runAt->format('H:i'));
                //         break;
                //     case 'monthlyOn':
                //         $schedule->monthlyOn($runAt->day, $runAt->format('H:i'));
                //         break;
                //     case 'yearlyOn':
                //         $schedule->yearlyOn($runAt->month, $runAt->day, $runAt->format('H:i'));
                //         break;
                //     default:
                //         $schedule->dailyAt($runAt->format('H:i'));
                //         break;
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
