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
use App\Models\TourPriceTier;
use App\Models\TourProduct;
use App\Models\TourProductDirection;
use App\Observers\AiInstructionObserver;
use App\Observers\BookingObserver;
use App\Observers\TourPriceTierObserver;
use App\Observers\TourProductDirectionObserver;
use App\Observers\TourProductObserver;
use App\Services\AutoExportScheduler;

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

        // AutoExportScheduler carries a per-request "already scheduled" flag
        // used to coalesce multiple observer fires into one terminating-phase
        // export. Singleton per request — same instance handed to all three
        // observers via constructor injection.
        $this->app->singleton(AutoExportScheduler::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Booking::observe(BookingObserver::class);

        // Phase 19.1 — fire supplier amendment notifications when a dispatched
        // booking's critical fields (date/time/pickup/pax) change. Also handles
        // driver/guide reassignment (removal notice + fresh dispatch).
        \App\Models\BookingInquiry::observe(\App\Observers\BookingInquiryObserver::class);

        // Phase 19.3a — accommodation amendment notifications on InquiryStay.
        // Watches stay_date/guest_count/nights/meal_plan/accommodation_id.
        // Only notifies accommodations previously dispatched via TG.
        \App\Models\InquiryStay::observe(\App\Observers\InquiryStayObserver::class);

        // Auto-export the tour pricing catalog to the static site whenever
        // any catalog row changes through Filament. See AutoExportScheduler
        // for the after-response coalesced execution model.
        TourProduct::observe(TourProductObserver::class);
        TourProductDirection::observe(TourProductDirectionObserver::class);
        TourPriceTier::observe(TourPriceTierObserver::class);

        // Lead CRM Phase 1 — denormalization of last_interaction_at and
        // next_followup_at on the lead row so queue views don't need a join.
        \App\Models\LeadInteraction::observe(\App\Observers\LeadInteractionObserver::class);
        \App\Models\LeadFollowUp::observe(\App\Observers\LeadFollowUpObserver::class);

        // Lead CRM Phase 2a — Livewire auto-discovery only walks app/Livewire/;
        // register Follow-up Queue child components explicitly so action
        // round-trips (Snooze, Done, Add follow-up) can resolve the component
        // by name.
        \Livewire\Livewire::component(
            'follow-up-queue.overdue',
            \App\Livewire\FollowUpQueue\OverdueFollowUpsTable::class,
        );
        \Livewire\Livewire::component(
            'follow-up-queue.no-followup',
            \App\Livewire\FollowUpQueue\LeadsWithoutFollowUpTable::class,
        );
        \Livewire\Livewire::component(
            'follow-up-queue.due-today',
            \App\Livewire\FollowUpQueue\DueTodayFollowUpsTable::class,
        );
        \Livewire\Livewire::component(
            'follow-up-queue.upcoming',
            \App\Livewire\FollowUpQueue\UpcomingFollowUpsTable::class,
        );
    }
}
