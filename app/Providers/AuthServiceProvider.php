<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\CashDrawer::class => \App\Policies\CashDrawerPolicy::class,
        \App\Models\CashierShift::class => \App\Policies\CashierShiftPolicy::class,
        \App\Models\CashTransaction::class => \App\Policies\CashTransactionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
