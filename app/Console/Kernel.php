<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Existing: run scheduled messages every minute
        $schedule->command('app:send-scheduled-messages')->everyMinute();

        // Daily owner report at 22:00 Tashkent time (Asia/Tashkent = UTC+5, so 17:00 UTC)
        $schedule->command('beds24:daily-report')
            ->dailyAt('17:00') // 22:00 Asia/Tashkent = 17:00 UTC
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('beds24:daily-report failed to run');
            });

        // Daily cash flow report at 23:00 Tashkent (18:00 UTC)
        $schedule->command('cash:daily-report')
            ->dailyAt('18:00')
            ->timezone('UTC')
            ->withoutOverlapping();

        // Monthly cash report on 1st of each month at 09:00 Tashkent (04:00 UTC)
        $schedule->command('cash:monthly-report')
            ->monthlyOn(1, '04:00')
            ->timezone('UTC')
            ->withoutOverlapping();
    }
}
