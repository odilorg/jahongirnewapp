<?php

namespace App\Console\Commands;

use App\Services\Beds24BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshBeds24Token extends Command
{
    protected $signature = 'beds24:refresh-token';
    protected $description = 'Force refresh Beds24 API access token and verify it works';

    public function handle(Beds24BookingService $service): int
    {
        $this->info('Refreshing Beds24 API token...');

        $result = $service->forceRefresh();

        if ($result['success']) {
            $this->info('Token refreshed successfully!');
            $this->info('Last refresh: ' . ($result['last_refresh'] ?? 'N/A'));

            // Verify by making a simple API call
            $this->info('Verifying token with test API call...');
            try {
                $bookings = $service->getBookings([
                    'propertyId' => ['41097'],
                    'arrivalFrom' => now()->format('Y-m-d'),
                    'arrivalTo' => now()->addDay()->format('Y-m-d'),
                ]);
                $this->info('API call successful - token is valid');
                Log::info('beds24:refresh-token: Token verified', ['bookings_found' => $bookings['count'] ?? 0]);
            } catch (\Throwable $e) {
                $this->warn('Token refreshed but API verification failed: ' . $e->getMessage());
                Log::warning('beds24:refresh-token: Token refreshed but verification failed', ['error' => $e->getMessage()]);
            }

            return self::SUCCESS;
        }

        $this->error('Token refresh FAILED: ' . $result['message']);
        Log::critical('beds24:refresh-token: Manual refresh failed', $result);

        // Alert owner
        $this->alertOwner($result['message']);

        return self::FAILURE;
    }

    private function alertOwner(string $error): void
    {
        $chatId = config('services.owner_alert_bot.owner_chat_id');
        if (!$chatId) return;

        try {
            $resolver = app(\App\Contracts\Telegram\BotResolverInterface::class);
            $transport = app(\App\Contracts\Telegram\TelegramTransportInterface::class);
            $bot = $resolver->resolve('owner-alert');
            $transport->sendMessage($bot, $chatId, "🚨 beds24:refresh-token command FAILED\n\nError: {$error}\n\nCheck Beds24 dashboard for token status.", ['parse_mode' => 'Markdown']);
        } catch (\Throwable $e) {
            // Ignore — alerting is best-effort
        }
    }
}
