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
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Phase 3a (Lead CRM) — pull unseen inbound mail from Zoho and turn
        // it into leads/interactions. Feature-flagged; the closure short-
        // circuits until an operator flips LEAD_EMAIL_INGESTION_ENABLED=true.
        $schedule->command('leads:fetch-zoho-emails')
            ->everyFiveMinutes()
            ->when(fn () => (bool) config('zoho.lead_email_ingestion_enabled'))
            ->withoutOverlapping(10)
            ->runInBackground();

        // Gmail -> CRM lead ingestion (Option B) — pull website contact-form
        // notifier mail from odilorg@gmail.com (server-side filtered to
        // from:info@jahongir-travel.uz, strict template) and create booking
        // inquiries. Feature-flagged: the closure short-circuits until an
        // operator flips GMAIL_LEAD_INGESTION_ENABLED=true; the command itself
        // ALSO re-checks the gate, so a scheduled run is a safe no-op while off.
        $schedule->command('leads:fetch-gmail-emails')
            ->everyFiveMinutes()
            ->when(fn () => (bool) config('gmail_leads.ingestion_enabled'))
            ->withoutOverlapping(10)
            ->runInBackground();

        // Phase 21 — email due reminders every 15 minutes.
        // Idempotent via notified_at marker, so safe to run frequently.
        $schedule->command('inquiry:send-reminders')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Phase 22 — daily evening operator recap via Telegram at 19:00 Tashkent.
        // Snapshot of tomorrows tours + readiness gaps + reminders.
        $schedule->command('recap:send-daily')
            ->dailyAt('19:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground();

        // Phase 23 — T-1h supplier ping. Runs every 15 min; targets
        // bookings whose pickup_time is now+45min..now+75min (Tashkent).
        // Idempotent via internal_notes markers.
        $schedule->command('supplier:ping-imminent-tours')
            ->everyFifteenMinutes()
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground();

        // Removed 2026-04-15: app:send-scheduled-messages scheduler disabled
        // (scheduled_messages table unused; feature deprecated)

        // ── BEDS24_DISABLED 2026-06-03 — Booking.com cancellation investigation ──
        // Beds24 token refresh - every 20 hours (well before 24h expiry)
        // $schedule->command('beds24:refresh-token')
        //     ->cron('0 */20 * * *') // Every 20 hours
        //     ->withoutOverlapping()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::critical('Scheduled beds24:refresh-token FAILED');
        //     });

        // ── BEDS24_DISABLED 2026-06-03 — Booking.com cancellation investigation ──
        // Daily owner report at 22:00 Tashkent time
        // $schedule->command('beds24:daily-report')
        //     ->dailyAt('22:00')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('beds24:daily-report failed to run');
        //     });

        // Daily cash flow report at 23:00 Tashkent
        $schedule->command('cash:daily-report')
            ->dailyAt('23:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Cashier-domain anomaly audit at 07:00 Tashkent.
        // Audits the previous day (which closed at 23:00) and sends a
        // PASS/WARN/ALERT summary to the owner Telegram. Replayable for
        // any past date via `php artisan cash:audit-daily --date=Y-m-d`.
        $schedule->command('cash:audit-daily')
            ->dailyAt('07:00')
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

        // ── BEDS24_DISABLED 2026-06-03 — Booking.com cancellation investigation ──
        // FX rate push: fetch CBU rates, then repair any near-term bookings whose sync is missing/failed.
        // Webhooks handle new/modified bookings in real-time; this catches any that slipped through.
        // $schedule->command('fx:push-payment-options')   // fetches today's CBU rate, no bulk push
        //     ->dailyAt('07:00')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('fx:push-payment-options failed to run');
        //     });

        // $schedule->command('fx:repair-missing --days=30')
        //     ->dailyAt('07:15')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('fx:repair-missing failed to run');
        //     });

        // Daily tour reminders at 20:00 Tashkent — staff Telegram + guest WhatsApp + driver/guide DM
        $schedule->command('tour:send-reminders')
            ->dailyAt('20:00')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // Hourly catch-up for guest reminders. Catches bookings made AFTER
        // the 20:00 batch (post-cutoff) and same-day bookings — incident
        // 2026-04-27 surfaced this gap. Idempotent via guest_reminder_sent_at;
        // re-running over an already-stamped row is a no-op.
        $schedule->command('tour:send-late-guest-reminders')
            ->cron('17 * * * *')   // off-zero minute to spread API load
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping()
            ->runInBackground();

        // Phase 29 — guest experience touchpoints (welcome / sunset tip /
        // feedback). Every 5 min; the dispatcher's compare-and-swap makes
        // re-runs safe. No-op until config('guest_experience.enabled').
        $schedule->command('guest-experience:send-due')
            ->everyFiveMinutes()
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping(10)
            ->runInBackground();

        // BOTH post-tour review systems are now MANUAL-ONLY (2026-05-07).
        //
        // Phase 1.7.0 / 2026-05-05 made the public TripAdvisor reminder
        // manual but left the Day-1 internal feedback request
        // (tour:send-review-requests) on schedule. In practice the
        // distinction was lost on operators: the auto-fired internal
        // message ("Hi {name} 😊 Hope the trip was a good one… ⭐
        // /feedback/{token}") feels indistinguishable from a public
        // review push, so operators kept getting surprised by sends
        // they didn't trigger (real example: Karl Marton, 2026-05-06,
        // INQ-2026-000087).
        //
        // Business decision: operators (not the system) decide which
        // guests to ping for ANY post-tour message — public review or
        // internal feedback. They read the tour-day vibes far better
        // than any date filter we can encode.
        //
        // Both legacy commands (tour:send-public-review-reminders and
        // tour:send-review-requests) are intentionally retained and
        // still runnable by hand for batch backfills, but neither is
        // scheduled.
        //
        // The Filament BookingInquiryResource view page has the two
        // operator buttons that replace the crons:
        //   - SendManualTripAdvisorReviewRequestAction  (public review)
        //   - SendManualInternalFeedbackRequestAction   (internal Day-1)
        // Both stamp their respective *_sent_at column on success only.

        // DISABLED 2026-05-11 by operator request — stop all automated hotel-
        // pickup email follow-ups, even for the private-tour-only filter
        // shipped in 5d80da4. Cause: Matthew Sandoz (INQ-103) confusion on
        // 2026-05-09 made operator decide that automated pickup emails are
        // no longer trusted; pickup-location collection is now manual via WA
        // or the Filament UI on the inquiry. The command itself stays in
        // app/Console/Commands/TourSendHotelRequests.php for now so it can
        // still be run by hand if needed. Re-enable by uncommenting.
        // $schedule->command('tour:send-hotel-requests')
        //     ->dailyAt('09:00')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping();

        // Viator inbound-email pipeline (V1):
        //   - fetch every 10 min  (booking@t1.viator.com via himalaya)
        //   - apply 2 min later   (auto-applies new bookings; amendments
        //                          + cancellations stay needs_review for
        //                          operator-driven reconciliation)
        // Stagger ensures fetch's output is visible to apply on the
        // same window. withoutOverlapping() prevents concurrent runs.
        $schedule->command('viator:fetch-emails')
            ->cron('*/10 * * * *')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // --only-future: scheduled runs never backfill historical bookings.
        // Manual `php artisan viator:apply-new-bookings` (no flag) remains
        // the operator-driven escape hatch for backfilling old rows.
        $schedule->command('viator:apply-new-bookings --only-future')
            ->cron('2-59/10 * * * *')
            ->timezone('Asia/Tashkent')
            ->withoutOverlapping();

        // GYG email pipeline — 3 independent stages, each processes only its
        // own state slice (fetch→fetched, process→parsed, apply→applied).
        // withoutOverlapping() prevents concurrent runs of the same stage.
        // GYG pipeline — staggered so each stage's input is visible to the
        // next. Previously all three fired at the same minute: fetch could
        // finish AFTER process had already scanned, leaving a 15-min gap
        // until the next tick. With stagger, process sees fetch's output
        // reliably within the same 15-min cycle.
        //
        // Fetch :*:00 · Process :*:05 · Apply :*:10
        $schedule->command('gyg:fetch-emails')
            ->cron('*/15 * * * *')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:fetch-emails FAILED');
            });

        $schedule->command('gyg:process-emails')
            ->cron('5-59/15 * * * *')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:process-emails FAILED');
            });

        $schedule->command('gyg:apply-bookings')
            ->cron('10-59/15 * * * *')
            ->withoutOverlapping()
            ->runInBackground()
            ->onFailure(function () {
                \Illuminate\Support\Facades\Log::error('Scheduled gyg:apply-bookings FAILED');
            });

        // Payment follow-up: nudge guests with unpaid links (4+ hours old)
        // DISABLED 2026-05-10 by operator request. Reason: the automated
        // WhatsApp nudge arrived while the operator was already in active
        // conversation with the guest (inquiry 109, Andrea Sterrantino),
        // creating a "bot talking over operator" UX. To re-enable,
        // uncomment the schedule block below. Idempotency / one-shot
        // guarantee in InquirySendPaymentReminders is unchanged — no
        // schema migration needed for re-enable.
        // $schedule->command('inquiry:send-payment-reminders')
        //     ->hourly()
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('Scheduled inquiry:send-payment-reminders FAILED');
        //     });

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

        // ── BEDS24_DISABLED 2026-06-03 — Booking.com cancellation investigation ──
        // FX: repair Beds24 sync rows stuck in pending/pushing state
        // Handles the case where DB::afterCommit() fired but the queue worker
        // was down, or a job was killed mid-run (server restart, OOM, etc.)
        // $schedule->command('fx:repair-stuck-syncs')
        //     ->everyThirtyMinutes()
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('fx:repair-stuck-syncs scheduled run FAILED');
        //     });

        // Retry permanently-failed Beds24 payment syncs within the push-attempt budget.
        // Nightly cadence is intentional — failed syncs need cooling off, not spam-retry.
        // $schedule->command('beds24:repair-failed-syncs')
        //     ->dailyAt('07:45')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('beds24:repair-failed-syncs scheduled run FAILED');
        //     });

        // Defensive: detect cash transactions with no sync row and create/dispatch them.
        // Runs daily alongside repair-failed so the two commands cover the full gap space.
        // $schedule->command('beds24:repair-missing-syncs')
        //     ->dailyAt('07:50')
        //     ->timezone('Asia/Tashkent')
        //     ->withoutOverlapping()
        //     ->runInBackground()
        //     ->onFailure(function () {
        //         \Illuminate\Support\Facades\Log::error('beds24:repair-missing-syncs scheduled run FAILED');
        //     });

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
