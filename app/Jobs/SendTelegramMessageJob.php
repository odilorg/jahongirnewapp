<?php

// app/Jobs/SendTelegramMessageJob.php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $message;
    public $chatId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($message, $chatId)
    {
        $this->message = $message;
        $this->chatId = $chatId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Telegram bot token
        $botToken = 'YOUR_TELEGRAM_BOT_TOKEN';

        // Send the request to Telegram API
        $response = Http::get("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $this->message->message,
        ]);

        // Handle the response if needed
        if ($response->failed()) {
            // Log or handle failure
        }
    }
}
