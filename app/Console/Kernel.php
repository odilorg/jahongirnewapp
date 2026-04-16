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
        // Removed 2026-04-15: app:send-scheduled-messages scheduler disabled
        // (scheduled_messages table unused; feature deprecated)

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

        // FX rate push: fetch CBU rates, then repair any near-term bookings whose sync is missing/failed.
        // Webhooks handle new/modified bookings in real-time; this catches any that slipped through.
        $schedule->command('fx:push-payment-options')   // fetches today's CBU rate, no bulk push
            ->dailyAt('07:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('fx:push-payment-options failed to run');
            });

        $schedule->command('fx:repair-missing --days=30')
            ->dailyAt('07:15')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('fx:repair-missing failed to run');
            });

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

        // GYG email pipeline — 3 independent stages, each processes only its
        // own state slice (fetch→fetched, process→parsed, apply→applied).
        // withoutOverlapping() prevents concurrent runs of the same stage.
        $schedule->command('gyg:fetch-emails')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:fetch-emails FAILED');
            });

        $schedule->command('gyg:process-emails')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:process-emails FAILED');
            });

        $schedule->command('gyg:apply-bookings')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:apply-bookings FAILED');
            });

        // Payment follow-up: nudge guests with unpaid links (4+ hours old)
        $schedule->command('inquiry:send-payment-reminders')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled inquiry:send-payment-reminders FAILED');
            });

        // Queue health: alert if jobs stuck >10 min (catches dead workers)
        $schedule->command('queue:health-check')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // FX: expire manager approvals whose TTL has passed — every 5 minutes
        $schedule->command('fx:expire-approvals')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // FX: repair Beds24 sync rows stuck in pending/pushing state
        // Handles the case where DB::afterCommit() fired but the queue worker
        // was down, or a job was killed mid-run (server restart, OOM, etc.)
        $schedule->command('fx:repair-stuck-syncs')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('fx:repair-stuck-syncs scheduled run FAILED');
            });

        // Retry permanently-failed Beds24 payment syncs within the push-attempt budget.
        // Nightly cadence is intentional — failed syncs need cooling off, not spam-retry.
        $schedule->command('beds24:repair-failed-syncs')
            ->dailyAt('07:45')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('beds24:repair-failed-syncs scheduled run FAILED');
            });

        // Defensive: detect cash transactions with no sync row and create/dispatch them.
        // Runs daily alongside repair-failed so the two commands cover the full gap space.
        $schedule->command('beds24:repair-missing-syncs')
            ->dailyAt('07:50')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('beds24:repair-missing-syncs scheduled run FAILED');
            });

        // FX: nightly exception report — violations, unconfirmed syncs, failed syncs
        $schedule->command('fx:nightly-report')
            ->dailyAt('08:30')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled fx:nightly-report FAILED');
            });
    }
}
