<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledMessage;
use App\Jobs\SendTelegramMessageJob;
use Illuminate\Support\Facades\Log;

class SendScheduledMessagesCommand extends Command
{
    protected $signature = 'app:send-scheduled-messages';
    protected $description = 'Dispatch jobs for any messages that are due to be sent.';

    public function handle()
    {
        Log::info('🟢 [SCHEDULER] Checking for pending messages...');

        // 1) Grab all due, pending messages
        $dueMessages = ScheduledMessage::where('scheduled_at', '<=', now())
            ->where('status', 'pending')
            ->get();

        if ($dueMessages->isEmpty()) {
            Log::info('🟡 [SCHEDULER] No pending messages found.');
            return;
        }

        // 2) Loop through each due message
        foreach ($dueMessages as $message) {
            // Check if there are any chats assigned via the pivot relationship
            if ($message->chats->isEmpty()) {
                Log::warning("🟠 [SCHEDULER] No chats found for message ID {$message->id}");
                continue;
            }

            Log::info("🟢 [SCHEDULER] Dispatching SendTelegramMessageJob for message ID {$message->id}");

            // 3) Dispatch the job
            SendTelegramMessageJob::dispatch($message);

            // 4) Mark the message as "processing" so it won't be picked up again immediately
            $message->update(['status' => 'processing']);
        }
    }
}
