<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetTelegramPosWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:pos:set-webhook {--url=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the webhook URL for Telegram POS bot';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $botToken = config('services.telegram_pos_bot.token');
        
        if (!$botToken) {
            $this->error('TELEGRAM_POS_BOT_TOKEN is not set in .env file');
            return Command::FAILURE;
        }
        
        $webhookUrl = $this->option('url') ?? config('services.telegram_pos_bot.webhook_url');
        
        if (!$webhookUrl) {
            $this->error('Webhook URL is not provided. Use --url option or set TELEGRAM_POS_WEBHOOK_URL in .env');
            return Command::FAILURE;
        }
        
        $this->info("Setting webhook to: {$webhookUrl}");
        
        $url = "https://api.telegram.org/bot{$botToken}/setWebhook";
        
        try {
            $response = Http::post($url, [
                'url' => $webhookUrl,
                'allowed_updates' => ['message', 'callback_query'],
            ]);
            
            $result = $response->json();
            
            if ($result['ok'] ?? false) {
                $this->info('✅ Webhook set successfully!');
                $this->info('Description: ' . ($result['description'] ?? 'N/A'));
                return Command::SUCCESS;
            } else {
                $this->error('❌ Failed to set webhook');
                $this->error('Error: ' . ($result['description'] ?? 'Unknown error'));
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
