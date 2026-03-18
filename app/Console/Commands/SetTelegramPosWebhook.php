<?php

namespace App\Console\Commands;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use Illuminate\Console\Command;

class SetTelegramPosWebhook extends Command
{
    protected $signature = 'telegram:pos:set-webhook {--url=}';

    protected $description = 'Set the webhook URL for Telegram POS bot';

    public function handle(BotResolverInterface $resolver, TelegramTransportInterface $transport)
    {
        $webhookUrl = $this->option('url') ?? config('services.telegram_pos_bot.webhook_url');

        if (!$webhookUrl) {
            $this->error('Webhook URL is not provided. Use --url option or set TELEGRAM_POS_WEBHOOK_URL in .env');
            return Command::FAILURE;
        }

        $this->info("Setting webhook to: {$webhookUrl}");

        try {
            $bot = $resolver->resolve('pos');
            $result = $transport->setWebhook($bot, $webhookUrl, extra: [
                'allowed_updates' => ['message', 'callback_query'],
            ]);

            if ($result->succeeded()) {
                $this->info('Webhook set successfully!');
                $this->info('Description: ' . ($result->description ?? 'N/A'));
                return Command::SUCCESS;
            } else {
                $this->error('Failed to set webhook');
                $this->error('Error: ' . ($result->description ?? 'Unknown error'));
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
