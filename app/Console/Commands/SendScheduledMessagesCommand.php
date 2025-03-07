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
        $dueMessages = ScheduledMessage::where('scheduled_at', '<=', now())
            ->where('status', 'pending')
            ->get();

        if ($dueMessages->isEmpty()) {
            $this->info('No pending messages are due right now.');
            return;
        }

        foreach ($dueMessages as $message) {
            $chatId = $message->chat->chat_id ?? null;
            if (!$chatId) {
                Log::warning('No valid chat_id found for message.', [
                    'message_id' => $message->id,
                ]);
                continue;
            }

            // Mark as processing to avoid re-dispatching
            $message->update(['status' => 'processing']);

            SendTelegramMessageJob::dispatch($message, $chatId);

            Log::info('Message dispatched to queue.', [
                'message_id' => $message->id,
                'chat_id'    => $chatId,
            ]);
        }

        $this->info('All due messages have been dispatched.');
    }
}
