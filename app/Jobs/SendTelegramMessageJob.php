<?php

namespace App\Jobs;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Models\ScheduledMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    public function handle(BotResolverInterface $resolver, TelegramTransportInterface $transport)
    {
        $this->message->update(['status' => 'processing']);

        try {
            $bot = $resolver->resolve('main');
        } catch (\Throwable $e) {
            Log::error('SendTelegramMessageJob: bot resolution failed', ['error' => $e->getMessage()]);
            $this->message->update(['status' => 'failed']);
            return;
        }

        foreach ($this->message->chats as $chat) {
            $telegramChatId = $chat->chat_id;

            $result = $transport->sendMessage($bot, $telegramChatId, $this->message->message);

            Log::info('Telegram API response', [
                'message_id' => $this->message->id,
                'chat_name'  => $chat->name,
                'chat_id'    => $chat->chat_id,
                'ok'         => $result->ok,
            ]);

            if (!$result->succeeded()) {
                $this->message->update(['status' => 'failed']);
                return;
            }
        }

        $this->message->update(['status' => 'sent']);
        Log::info('Message sent successfully.', ['message_id' => $this->message->id]);
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
