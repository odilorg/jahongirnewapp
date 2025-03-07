<?php

namespace App\Jobs;

use App\Models\ScheduledMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ScheduledMessage $message;

    /**
     * Create a new job instance.
     */
    public function __construct(ScheduledMessage $message)
    {
        $this->message = $message;
        Log::debug('SendTelegramMessageJob initialized', [
            'message_id' => $message->id,
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $botToken = config('services.telegram_bot.token');

        if (!$botToken) {
            Log::error('Telegram bot token is missing!');
            $this->message->update(['status' => 'failed']);
            return;
        }

        // Mark the message as "processing" if you like
        $this->message->update(['status' => 'processing']);

        // Loop through each related Chat record in the pivot
        foreach ($this->message->chats as $chat) {
            // The actual Telegram chat ID is stored in the chat_id column
            $telegramChatId = $chat->chat_id;

            // Send message to Telegram
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $telegramChatId,
                'text'    => $this->message->message,
            ]);

            // Log the API response for debugging
            Log::info('Telegram API response', [
                'message_id' => $this->message->id,
                'chat_name'  => $chat->name,
                'chat_id'    => $chat->chat_id,
                'response'   => $response->json(),
            ]);

            if (!$response->successful()) {
                // If any chat fails, we can mark it as "failed". 
                // (Alternatively, keep track of partial failures).
                $this->message->update(['status' => 'failed']);
                return;
            }
        }

        // If we got through the loop successfully, mark the message as sent
        $this->message->update(['status' => 'sent']);
        Log::info('Message sent successfully.', [
            'message_id' => $this->message->id,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception)
    {
        Log::critical('SendTelegramMessageJob failed.', [
            'message_id' => $this->message->id,
            'error'      => $exception->getMessage(),
        ]);

        $this->message->update(['status' => 'failed']);
    }
}
