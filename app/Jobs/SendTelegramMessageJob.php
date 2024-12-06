<?php

namespace App\Jobs;

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

    public $message;
    public $chatId;

    /**
     * Create a new job instance.
     *
     * @param mixed $message
     * @param string $chatId
     * @return void
     */
    public function __construct($message, $chatId)
    {
        $this->message = $message;
        $this->chatId = $chatId;
        // Log the input data
        Log::info('SendTelegramMessageJob initialized.', [
            'message_id' => $this->message->id ?? 'N/A',
            'chat_id' => $this->chatId,
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Retrieve the bot token from environment variables
        $botToken = config('services.telegram_bot');

        Log::info('Bot token:', ['bot_token' => config('services.telegram_bot')]);


        // Log the bot token (caution: avoid logging sensitive tokens in production)
        Log::info('Using bot token for Telegram API.', [
            'bot_token' => $botToken ? 'Token retrieved' : 'Token missing',
        ]);

        // Send the request to the Telegram API
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $this->message->message,
        ]);

        // Log the response status and body
        if ($response->successful()) {
            // Update the message status and log success
            $this->message->update(['status' => 'sent']);
            Log::info('Telegram message sent successfully.', [
                'message_id' => $this->message->id,
                'chat_id' => $this->chatId,
                'response' => $response->json(),
            ]);
        } else {
            // Log the failure and update message status to 'failed'
            $this->message->update(['status' => 'failed']);
            Log::error('Failed to send Telegram message.', [
                'message_id' => $this->message->id ?? 'N/A',
                'chat_id' => $this->chatId,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);
        }
    }

    /**
     * Handle failure of the job.
     *
     * @param \Exception $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        // Log the exception for debugging purposes
        Log::critical('SendTelegramMessageJob failed with an exception.', [
            'message_id' => $this->message->id ?? 'N/A',
            'chat_id' => $this->chatId,
            'error_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Update message status to 'failed'
        $this->message->update(['status' => 'failed']);
    }
}
