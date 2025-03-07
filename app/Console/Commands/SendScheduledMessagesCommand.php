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

    $dueMessages = ScheduledMessage::where('scheduled_at', '<=', now())
        ->where('status', 'pending')
        ->get();

    if ($dueMessages->isEmpty()) {
        Log::info('🟡 [SCHEDULER] No pending messages found.');
        return;
    }

    foreach ($dueMessages as $message) {
        $chatId = $message->chat->chat_id ?? null;

        if (!$chatId) {
            Log::warning('🟠 [SCHEDULER] No chat_id found for message ID ' . $message->id);
            continue;
        }

        Log::info('🟢 [SCHEDULER] Dispatching SendTelegramMessageJob for message ID ' . $message->id);
        
        // Dispatch the job
        SendTelegramMessageJob::dispatch($message, $chatId);

        // Mark message as "processing" so it doesn't get picked up again too soon
        $message->update(['status' => 'processing']);
    }
}
    // public function handle()
    // {
    //     $dueMessages = ScheduledMessage::where('scheduled_at', '<=', now())
    //         ->where('status', 'pending')
    //         ->get();

    //     if ($dueMessages->isEmpty()) {
    //         $this->info('No pending messages are due right now.');
    //         return;
    //     }

    //     foreach ($dueMessages as $message) {
    //         $chatId = $message->chat->chat_id ?? null;
    //         if (!$chatId) {
    //             Log::warning('No valid chat_id found for message.', [
    //                 'message_id' => $message->id,
    //             ]);
    //             continue;
    //         }

    //         // Mark as processing to avoid re-dispatching
    //         $message->update(['status' => 'processing']);

    //         SendTelegramMessageJob::dispatch($message, $chatId);

    //         Log::info('Message dispatched to queue.', [
    //             'message_id' => $message->id,
    //             'chat_id'    => $chatId,
    //         ]);
    //     }

    //     $this->info('All due messages have been dispatched.');
    // }
}
