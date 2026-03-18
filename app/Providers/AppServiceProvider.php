<?php

namespace App\Providers;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\BotSecretProviderInterface;
use App\Services\Telegram\BotAuditLogger;
use App\Services\Telegram\BotSecretProvider;
use App\Services\Telegram\FallbackBotResolver;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
    }
}
