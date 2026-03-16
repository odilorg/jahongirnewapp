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

        // Beds24 token refresh - every 20 hours (well before 24h expiry)
        $schedule->command('beds24:refresh-token')
            ->cron('0 */20 * * *') // Every 20 hours
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::critical('Scheduled beds24:refresh-token FAILED');
            });

        // Daily owner report at 22:00 Tashkent time
        $schedule->command('beds24:daily-report')
            ->dailyAt('22:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('beds24:daily-report failed to run');
            });

        // Daily cash flow report at 23:00 Tashkent
        $schedule->command('cash:daily-report')
            ->dailyAt('23:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Daily reconciliation at 21:00 Tashkent - check today's departures
        $schedule->command('cash:reconcile')
            ->dailyAt('21:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Weekly full reconciliation (last 7 days) on Sundays at 10:00 Tashkent
        $schedule->command('cash:reconcile --period=7d')
            ->weeklyOn(0, '10:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Monthly cash report on 1st of each month at 09:00 Tashkent
        $schedule->command('cash:monthly-report')
            ->monthlyOn(1, '09:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Daily tour reminders at 20:00 Tashkent — staff Telegram + guest WhatsApp + driver/guide DM
        $schedule->command('tour:send-reminders')
            ->dailyAt('20:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Post-tour review requests at 10:00 Tashkent — WhatsApp/email guests whose tour ended yesterday
        $schedule->command('tour:send-review-requests')
            ->dailyAt('10:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Hotel pickup requests at 09:00 Tashkent — email guests with bookings 3-30 days out, no hotel set
        $schedule->command('tour:send-hotel-requests')
            ->dailyAt('09:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();
    }
}
