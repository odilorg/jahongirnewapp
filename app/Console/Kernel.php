<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon;
use App\Models\ScheduledMessage;
use App\Models\Chat;
use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        try {
            // Fetch scheduled messages that are not in the past
            $scheduledMessages = ScheduledMessage::where('scheduled_at', '>=', now())->get();

            foreach ($scheduledMessages as $message) {
                $runAt = Carbon::parse($message->scheduled_at);

                // Determine the frequency method based on the message's frequency
                $frequencyMethod = $this->mapFrequencyToMethod($message->frequency);

                // Get the related chat
                $chat = $message->chat->chat_id ?? null;

                if ($chat) {
                    $schedule->call(function () use ($message, $chat) {
                        try {
                            // Dispatch the job
                            SendTelegramMessageJob::dispatch($message, $chat);

                            // Log success
                            Log::info('Scheduled message dispatched successfully.', [
                                'message_id' => $message->id,
                                'chat_id' => $chat,
                                'scheduled_at' => $message->scheduled_at,
                            ]);
                        } catch (\Exception $e) {
                            // Log job dispatch error
                            Log::error('Failed to dispatch scheduled message.', [
                                'message_id' => $message->id,
                                'chat_id' => $chat,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    })
                    ->timezone('Asia/Samarkand')
                    ->at($runAt->format('H:i'))
                    ->when(function () use ($message) {
                        // Ensure the message runs only on the correct day
                        return now()->isSameDay($message->scheduled_at);
                    });

                    // Debug log for schedule setup
                    Log::info('Scheduled message configured.', [
                        'message_id' => $message->id,
                        'chat_id' => $chat,
                        'frequency' => $message->frequency,
                        'run_at' => $runAt->toDateTimeString(),
                    ]);
                } else {
                    // Log missing chat
                    Log::warning('Chat not found for scheduled message.', [
                        'message_id' => $message->id,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Log any exceptions during the scheduling process
            Log::error('Error in scheduling messages.', [
                'error' => $e->getMessage(),
            ]);
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
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
