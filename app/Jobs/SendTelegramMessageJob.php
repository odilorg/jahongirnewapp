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
        // $chatId = $this->argument('chatId');

        // // Retrieve the Car and its associated Driver
        // $car = Chatid::with('')->find($carId);

        // if (!$car || !$car->driver) {
        //     $this->error('Car or Driver not found.');
        //     return;
        // }

        // // Retrieve the license number
        // $licenseNumber = $car->driver->license_number;
        // $driverName = $car->driver->name;
        
        $botToken = $_ENV['JAHONGIRCLEANINGBOT'];
        $chatId = $this->message->chat_id;
        $chatId = Chatid::find($chatId);
        $chatIdd = $chatId->chatid;
       // dd($chatIdd);

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatIdd,
            'text' => $this->message->message,
        ]);
    }
    
}
