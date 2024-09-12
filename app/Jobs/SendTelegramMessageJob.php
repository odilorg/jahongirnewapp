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
     * @param mixed $message
     * @param string $chatId
     * @return void
     */
    public function __construct($message, $chatId)
    {
        $this->message = $message;
        $this->chatId = $chatId;
//dd($this->chatId);
        
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Retrieve the bot token from environment variables
        $botToken = env('JAHONGIRCLEANINGBOT');
    //    dd($botToken);
        // Send the request to the Telegram API
        $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $this->message->message,
        ]);
//dd($response);
        if ($response->failed()) {
            // Log or handle failure
        }
    }
}
