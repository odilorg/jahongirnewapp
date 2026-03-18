<?php

namespace App\Providers;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Services\Telegram\BotAuditLogger;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);
    }
}
