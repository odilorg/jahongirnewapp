<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use App\Models\ScheduledMessage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

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
        $botToken = '5323496366:AAECv7ayX2gdcUNEhkbUVT2MAr8tvVVdxuA';
        $chatId = '-653810568';

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $this->message->message,
        ]);
    }
}
