<?php

namespace App\Jobs;


use App\Models\Chatid;
use Illuminate\Bus\Queueable;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;


class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $message;

    /**
     * Create a new job instance.
     */
    public function __construct(ScheduledMessage $message)
    {
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $botToken = env('JAHONGIRCLEANINGBOT');
        
        // Retrieve the Chat ID from the database
        $chatIdModel = Chatid::find($this->message->chat_id);
        if (!$chatIdModel) {
            // Handle error (e.g., log it, throw an exception, or return)
            Log::error('Chat ID not found: ' . $this->message->chat_id);
            return;
        }
        
        $chatIdd = $chatIdModel->chatid;

        try {
            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatIdd,
                'text' => $this->message->message,
            ]);
        } catch (\Exception $e) {
            // Handle the exception (e.g., log it or retry the job)
            Log::error('Failed to send message: ' . $e->getMessage());
        }
    }
}
