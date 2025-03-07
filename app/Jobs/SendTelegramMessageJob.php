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

    /**
     * The scheduled message to send.
     */
    protected ScheduledMessage $message;

    /**
     * The Telegram chat ID.
     */
    protected string $chatId;

    /**
     * Create a new job instance.
     */
    public function __construct(ScheduledMessage $message, string $chatId)
    {
        $this->message = $message;
        $this->chatId = $chatId;

        Log::debug('SendTelegramMessageJob initialized.', [
            'message_id' => $this->message->id,
            'chat_id'    => $this->chatId,
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // Get the bot token from config/services.php
        $botToken = config('services.telegram_bot.token');

        if (!$botToken) {
            Log::error('Telegram bot token is missing!');
            $this->message->update(['status' => 'failed']);
            return;
        }

        // Ensure message content is a string
        $text = (string) $this->message->message;

        // Send to Telegram API
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $this->chatId,
            'text'    => $text,
        ]);

        if ($response->successful()) {
            // Mark as sent
            $this->message->update(['status' => 'sent']);
            Log::info('Message sent successfully.', [
                'message_id' => $this->message->id,
            ]);

            // If this message is recurring, reschedule it
            $this->message->reschedule();
        } else {
            // Mark as failed
            $this->message->update(['status' => 'failed']);
            Log::error('Failed to send message.', [
                'message_id'  => $this->message->id,
                'status_code' => $response->status(),
                'response'    => $response->body(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception)
    {
        Log::critical('SendTelegramMessageJob failed.', [
            'message_id' => $this->message->id,
            'chat_id'    => $this->chatId,
            'error'      => $exception->getMessage(),
        ]);

        $this->message->update(['status' => 'failed']);
    }
}
