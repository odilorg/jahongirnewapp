<?php

namespace App\Providers;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\BotSecretProviderInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Services\Telegram\BotAuditLogger;
use App\Services\Telegram\BotSecretProvider;
use App\Services\Telegram\FallbackBotResolver;
use App\Services\Telegram\TelegramTransport;
use Illuminate\Support\ServiceProvider;
use App\Models\AiInstruction;
use App\Models\Booking;
use App\Observers\AiInstructionObserver;
use App\Observers\BookingObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BotAuditLoggerInterface::class, BotAuditLogger::class);
        $this->app->bind(BotSecretProviderInterface::class, BotSecretProvider::class);
        $this->app->bind(BotResolverInterface::class, FallbackBotResolver::class);
        $this->app->bind(TelegramTransportInterface::class, TelegramTransport::class);

        // Explicitly transient (NOT singleton) — KitchenGuestService carries
        // per-request state ($lastFetchedCounts). Long-lived queue workers must
        // get a fresh instance per job to prevent stale cache bleeding between jobs.
        $this->app->bind(\App\Services\KitchenGuestService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
    }
}
