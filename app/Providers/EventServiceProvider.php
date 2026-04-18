<?php

namespace App\Providers;

use App\Events\Ledger\LedgerEntryRecorded;
use App\Listeners\Ledger\UpdateBalanceProjections;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        // L-005 — keep ledger balance projections in sync with every
        // ledger write. Synchronous listener; runs inside the ledger
        // write transaction so the projection and the entry are atomic.
        LedgerEntryRecorded::class => [
            UpdateBalanceProjections::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
