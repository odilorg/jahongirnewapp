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
    // Retrieve the bot token from config
    $botToken = config('services.telegram_bot.token');

    // Debug: Log the bot token
    Log::info('Bot token from config:', ['bot_token' => $botToken]);

    if (!$botToken) {
        Log::error('Telegram bot token is missing!');
        return; // Exit early to prevent API errors
    }

    // Debug: Log the message content
    Log::info('Message content:', ['message' => $this->message->message]);

    // Ensure the text is a string
    $text = is_array($this->message->message)
        ? implode("\n", $this->message->message)
        : $this->message->message;

    // Debug: Log the payload
    Log::info('Payload being sent to Telegram:', [
        'chat_id' => $this->chatId,
        'text' => $text,
    ]);

    // Send the request to the Telegram API
    $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
        'chat_id' => $this->chatId,
        'text' => $text,
    ]);

    // Debug: Log the response
    Log::info('Telegram API response:', [
        'status' => $response->status(),
        'body' => $response->json(),
    ]);

    if ($response->successful()) {
        $this->message->update(['status' => 'sent']);
        Log::info('Message sent successfully.');
    } else {
        $this->message->update(['status' => 'failed']);
        Log::error('Failed to send message.', [
            'status_code' => $response->status(),
            'response' => $response->body(),
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
