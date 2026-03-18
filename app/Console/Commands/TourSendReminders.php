<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramNotificationJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TourSendReminders extends Command
{
    protected $signature   = 'tour:send-reminders {--dry-run : Print output without actually sending messages}';
    protected $description = 'Send daily tour reminders — Telegram staff alert (Phase 1) + WhatsApp guest reminders (Phase 2)';

    private int $ownerChatId;

    public function __construct()
    {
        parent::__construct();
        $this->ownerChatId = (int) config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '0'));
    }

    public function handle(): int
    {
        $dryRun    = $this->option('dry-run');
        $tz        = 'Asia/Tashkent';
        $tomorrow  = Carbon::now($tz)->addDay()->toDateString();
        $dateLabel = Carbon::now($tz)->addDay()->format('D, d M Y');
        $today     = Carbon::now($tz)->toDateString();

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $this->info("Fetching confirmed bookings for tomorrow: {$tomorrow}");

        // -----------------------------------------------------------------------
        // Query tomorrow's confirmed bookings (all — including do_not_remind, so
        // we can calculate accurate summary totals)
        // -----------------------------------------------------------------------
        $allBookings = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->whereDate('bookings.booking_start_date_time', $tomorrow)
            ->where('bookings.booking_status', 'confirmed')
            ->select([
                'bookings.id',
                'bookings.guest_id',
                'bookings.driver_id',
                'bookings.guide_id',
                'bookings.pickup_location',
                'bookings.booking_start_date_time',
                'bookings.booking_source',
                'bookings.booking_number',
                'bookings.do_not_remind',
                'bookings.special_requests',
                'tours.title        as tour_title',
                'tours.pickup_time  as tour_pickup_time',
                'tours.driver_route as tour_driver_route',
                'tours.driver_brief as tour_driver_brief',
                DB::raw("TIME_FORMAT(bookings.booking_start_date_time, '%H:%i') as booking_pickup_time"),
                'guests.first_name',
                'guests.last_name',
                'guests.phone',
                'guests.country',
                'guests.number_of_people',
            ])
            ->orderBy('tours.title')
            ->orderBy('guests.last_name')
            ->get();

        // Bookings eligible for reminders (do_not_remind = false)
        $bookings = $allBookings->where('do_not_remind', false)->values();

        // -----------------------------------------------------------------------
        // Pre-calculate summary totals for Telegram message
        // -----------------------------------------------------------------------
        $summaryTotalTours    = $allBookings->groupBy('tour_title')->count();
        $summaryTotalBookings = $allBookings->count();
        $summaryTotalPax      = $allBookings->sum('number_of_people');
        $summaryOptedOut      = $allBookings->where('do_not_remind', true)->count();

        $summaryWhatsappEligible = 0;
        $summaryNoPhone          = 0;
        foreach ($bookings as $b) {
            $raw = trim($b->phone ?? '');
            if (empty($raw)) {
                $summaryNoPhone++;
            } else {
                $normalized = $this->normalizePhone($raw);
                if ($normalized !== null) {
                    $summaryWhatsappEligible++;
                } else {
                    $summaryNoPhone++;
                }
            }
        }

        // -----------------------------------------------------------------------
        // Phase 1 — Staff Telegram Alert
        // -----------------------------------------------------------------------
        $telegramMessage = $this->buildStaffMessage(
            $bookings,
            $dateLabel,
            $summaryTotalTours,
            $summaryTotalBookings,
            $summaryTotalPax,
            $summaryWhatsappEligible,
            $summaryOptedOut,
            $summaryNoPhone
        );

        $this->info('--- Telegram Staff Message ---');
        $this->line($telegramMessage);
        $this->info('------------------------------');

        if (!$dryRun) {
            $telegramOk = $this->sendTelegram($telegramMessage);
            $this->logTelegram($telegramOk ? 'sent' : 'failed', $tomorrow);
            $this->info($telegramOk ? '✅ Telegram staff alert dispatched.' : '⚠ Telegram dispatch failed.');
        } else {
            $this->info('[DRY-RUN] Telegram alert would be sent.');
        }

        // -----------------------------------------------------------------------
        // Phase 2 — Guest WhatsApp Reminders
        // -----------------------------------------------------------------------
        if ($bookings->isEmpty()) {
            $this->info('No bookings → skipping WhatsApp reminders.');
            return self::SUCCESS;
        }

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;

        /** @var array<string,true> $phonesThisRun In-memory dedup set */
        $phonesThisRun = [];

        /** @var array<int,array> $pendingMessages Messages to send after loop (stop wacli-sync once) */
        $pendingMessages = [];

        foreach ($bookings as $booking) {
            $rawPhone  = trim($booking->phone ?? '');
            $guestName = trim("{$booking->first_name} {$booking->last_name}");

            // ── Phone validation ──────────────────────────────────────────────
            if (empty($rawPhone)) {
                $this->warn("  ⚠ Skipping {$guestName} — no phone number.");
                $this->logWhatsApp($booking, null, 'skipped', 'no phone number', $tomorrow, $dryRun);
                $skipped++;
                continue;
            }

            $phone = $this->normalizePhone($rawPhone);

            if ($phone === null) {
                $this->warn("  ⚠ Skipping {$guestName} — invalid phone format: {$rawPhone}");
                $this->logWhatsApp($booking, $rawPhone, 'skipped', 'invalid phone format', $tomorrow, $dryRun);
                $skipped++;
                continue;
            }

            // ── Duplicate phone (within this run) ────────────────────────────
            if (isset($phonesThisRun[$phone])) {
                $this->warn("  ⚠ Skipping {$guestName} ({$phone}) — duplicate phone, already messaged this run.");
                $this->logWhatsApp($booking, $phone, 'skipped', 'duplicate phone, already messaged this run', $tomorrow, $dryRun);
                $skipped++;
                continue;
            }

            // ── Idempotency: already sent for this tour date? ─────────────────
            // Key: booking_id + channel + scheduled_for_date (the actual tour date)
            $alreadySent = DB::table('tour_reminder_logs')
                ->where('booking_id', $booking->id)
                ->where('channel', 'whatsapp')
                ->where('scheduled_for_date', $tomorrow)
                ->exists();

            if ($alreadySent) {
                $this->warn("  ⚠ Skipping {$guestName} — already sent WhatsApp for tour date {$tomorrow} (booking #{$booking->id}).");
                $skipped++;
                continue;
            }

            // ── Build message ─────────────────────────────────────────────────
            $pickupTime = $booking->booking_pickup_time   // exact time from booking (from GYG)
                ?: ($booking->tour_pickup_time
                    ? substr($booking->tour_pickup_time, 0, 5)
                    : '08:30');

            $waMessage = $this->buildGuestMessage(
                $booking->first_name,
                $booking->tour_title,
                $dateLabel,
                $booking->pickup_location,
                $pickupTime
            );

            $this->info("  📱 WhatsApp → {$phone} ({$guestName})");
            $this->line("     Message preview:\n" . $this->indent($waMessage, 5));

            $phonesThisRun[$phone] = true;

            if ($dryRun) {
                $sent++;
                continue;
            }

            // Collect valid messages to send — we stop/start wacli-sync once outside the loop
            $pendingMessages[] = [
                'phone'    => $phone,
                'jid'      => ltrim($phone, '+') . '@s.whatsapp.net',
                'message'  => $waMessage,
                'booking'  => $booking,
                'name'     => $guestName,
            ];
        }

        // ── Queue messages via wa-queue HTTP API ──────────────────────────────
        if (!empty($pendingMessages) && !$dryRun) {
            foreach ($pendingMessages as $item) {
                $dedupeKey = 'reminder-' . $item['booking']->id . '-' . $tomorrow;
                $payload   = json_encode([
                    'to'         => $item['jid'],
                    'message'    => $item['message'],
                    'dedupe_key' => $dedupeKey,
                ]);

                $ch = curl_init('http://127.0.0.1:8765/send');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                $resp    = curl_exec($ch);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($curlErr) {
                    $this->error("     ❌ Queue API error for {$item['phone']}: {$curlErr}");
                    $this->logWhatsApp($item['booking'], $item['phone'], 'failed', $curlErr, $tomorrow, false);
                    $failed++;
                } else {
                    $data = json_decode($resp, true);
                    $this->info("     ✅ Queued for {$item['phone']} (id={$data['id']})");
                    Log::info('TourSendReminders: WhatsApp queued', [
                        'phone'      => $item['phone'],
                        'guest'      => $item['name'],
                        'queue_id'   => $data['id'],
                        'booking_id' => $item['booking']->id,
                    ]);
                    $this->logWhatsApp($item['booking'], $item['phone'], 'sent', null, $tomorrow, false);
                    $sent++;
                }
            }
        }

        $this->info("WhatsApp summary: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        // -----------------------------------------------------------------------
        // Phase 3 — Driver & Guide Telegram Notifications
        // -----------------------------------------------------------------------
        $this->info('--- Phase 3: Driver/Guide Telegram Notifications ---');
        $this->sendDriverGuideNotifications($allBookings, $dateLabel, $dryRun, $tomorrow);

        return self::SUCCESS;
    }

    private function sendDriverGuideNotifications(
        \Illuminate\Support\Collection $bookings,
        string $dateLabel,
        bool $dryRun, string $tomorrow
    ): void {
        if (empty($this->driverGuideBotToken)) {
            $this->warn('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE not set — skipping driver/guide notifications.');
            return;
        }

        // Collect unique driver/guide IDs across all bookings
        $driverIds = $bookings->pluck('driver_id')->filter()->unique()->values();
        $guideIds  = $bookings->pluck('guide_id')->filter()->unique()->values();

        if ($driverIds->isEmpty() && $guideIds->isEmpty()) {
            $this->info('No drivers or guides assigned to tomorrow\'s bookings.');
            return;
        }

        // Fetch drivers with telegram_chat_id
        $drivers = DB::table('drivers')
            ->whereIn('id', $driverIds)
            ->whereNotNull('telegram_chat_id')
            ->get()
            ->keyBy('id');

        // Fetch guides with telegram_chat_id
        $guides = DB::table('guides')
            ->whereIn('id', $guideIds)
            ->whereNotNull('telegram_chat_id')
            ->get()
            ->keyBy('id');

        // Group bookings by driver_id then guide_id and send one message per person
        $notified = [];

        foreach ($bookings as $booking) {
            // Notify driver
            if ($booking->driver_id && isset($drivers[$booking->driver_id])) {
                $driver = $drivers[$booking->driver_id];
                $key    = 'driver_' . $driver->id;
                if (!isset($notified[$key])) {
                    $assignedBookings = $bookings->where('driver_id', $booking->driver_id);
                    $message = $this->buildDriverGuideMessage($assignedBookings, $dateLabel);
                    $this->info("  🚗 Telegram → Driver: {$driver->first_name} {$driver->last_name}");
                    if (!$dryRun) {
                        // Idempotency: skip if already sent today for this driver + date
                        $alreadySent = DB::table('tour_reminder_logs')
                            ->where('channel', 'telegram_driver')
                            ->where('scheduled_for_date', $tomorrow)
                            ->where('guest_id', $driver->id) // repurpose guest_id to store driver_id
                            ->exists();

                        if (!$alreadySent) {
                            $ok = $this->sendTelegramDirect($driver->telegram_chat_id, $message);
                            DB::table('tour_reminder_logs')->insert([
                                'booking_id'         => null,
                                'guest_id'           => $driver->id,
                                'channel'            => 'telegram_driver',
                                'scheduled_for_date' => $tomorrow,
                                'phone'              => null,
                                'status'             => $ok ? 'sent' : 'failed',
                                'error_message'      => null,
                                'reminded_at'        => now(),
                                'created_at'         => now(),
                                'updated_at'         => now(),
                            ]);
                        } else {
                            $this->warn("  ⚠ Driver {$driver->first_name} already notified today — skipping.");
                        }
                    }
                    $notified[$key] = true;
                }
            }

            // Notify guide
            if ($booking->guide_id && isset($guides[$booking->guide_id])) {
                $guide = $guides[$booking->guide_id];
                $key   = 'guide_' . $guide->id;
                if (!isset($notified[$key])) {
                    $assignedBookings = $bookings->where('guide_id', $booking->guide_id);
                    $message = $this->buildDriverGuideMessage($assignedBookings, $dateLabel);
                    $this->info("  🎒 Telegram → Guide: {$guide->first_name} {$guide->last_name}");
                    if (!$dryRun) {
                        $alreadySent = DB::table('tour_reminder_logs')
                            ->where('channel', 'telegram_guide')
                            ->where('scheduled_for_date', $tomorrow)
                            ->where('guest_id', $guide->id)
                            ->exists();

                        if (!$alreadySent) {
                            $ok = $this->sendTelegramDirect($guide->telegram_chat_id, $message);
                            DB::table('tour_reminder_logs')->insert([
                                'booking_id'         => null,
                                'guest_id'           => $guide->id,
                                'channel'            => 'telegram_guide',
                                'scheduled_for_date' => $tomorrow,
                                'phone'              => null,
                                'status'             => $ok ? 'sent' : 'failed',
                                'error_message'      => null,
                                'reminded_at'        => now(),
                                'created_at'         => now(),
                                'updated_at'         => now(),
                            ]);
                        } else {
                            $this->warn("  ⚠ Guide {$guide->first_name} already notified today — skipping.");
                        }
                    }
                    $notified[$key] = true;
                }
            }
        }

        // Warn about unregistered staff
        foreach ($driverIds as $dId) {
            if (!isset($drivers[$dId])) {
                $d = DB::table('drivers')->find($dId);
                if ($d) $this->warn("  ⚠ Driver {$d->first_name} {$d->last_name} has no Telegram registered.");
            }
        }
        foreach ($guideIds as $gId) {
            if (!isset($guides[$gId])) {
                $g = DB::table('guides')->find($gId);
                if ($g) $this->warn("  ⚠ Guide {$g->first_name} {$g->last_name} has no Telegram registered.");
            }
        }

        $this->info('Driver/guide notifications done. Notified: ' . count($notified));
    }

    private function buildDriverGuideMessage(
        \Illuminate\Support\Collection $bookings,
        string $dateLabel
    ): string {
        $lines   = [];
        $lines[] = "📋 <b>Ertangi tur rejasi — {$dateLabel}</b>";

        // Group by tour so route/brief appear once per tour, not per guest
        $byTour = $bookings->groupBy('tour_title');

        foreach ($byTour as $tourTitle => $tourBookings) {
            $firstBooking = $tourBookings->first();
            $route        = trim($firstBooking->tour_driver_route ?? '');
            $brief        = trim($firstBooking->tour_driver_brief ?? '');

            $lines[] = '';
            $lines[] = "━━━━━━━━━━━━━━━━━━━━";
            $lines[] = "🏕 <b>{$tourTitle}</b>";

            // Route if available
            if ($route) {
                $lines[] = '';
                $lines[] = "🗺 <b>Marshrut:</b>";
                $lines[] = $route;
            }

            // Guest list
            $lines[] = '';
            $lines[] = "👥 <b>Mehmonlar:</b>";
            foreach ($tourBookings as $b) {
                $guestName  = trim("{$b->first_name} {$b->last_name}");
                $pax        = (int) $b->number_of_people;
                $flag       = $this->countryFlag($b->country ?? '');
                $pickup     = $b->pickup_location ?: 'TBD';
                $pickupTime = $b->booking_pickup_time
                    ?: ($b->tour_pickup_time ? substr($b->tour_pickup_time, 0, 5) : '08:30');

                $lines[] = "• {$guestName} {$flag} — {$pax} pax";
                $lines[] = "  🕗 {$pickupTime} | 🏨 {$pickup}";
            }

            // Driver brief/notes if available
            if ($brief) {
                $lines[] = '';
                $lines[] = "📝 <b>Ko'rsatmalar:</b>";
                $lines[] = $brief;
            }
        }

        $lines[] = '';
        $lines[] = "━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "📞 Savollar uchun: +998 91 555 08 08";

        return implode("\n", $lines);
    }

    private function sendTelegramDirect(string $chatId, string $text): bool
    {
        try {
            $resolver = app(\App\Contracts\Telegram\BotResolverInterface::class);
            $transport = app(\App\Contracts\Telegram\TelegramTransportInterface::class);
            $bot = $resolver->resolve('driver-guide');
            $result = $transport->sendMessage($bot, $chatId, $text, ['parse_mode' => 'HTML']);

            if (!$result->succeeded()) {
                Log::error('TourSendReminders: driver/guide Telegram request failed', ['chat_id' => $chatId, 'status' => $result->httpStatus]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('TourSendReminders: driver/guide Telegram exception', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // Message builders
    // =========================================================================

    private function buildStaffMessage(
        \Illuminate\Support\Collection $bookings,
        string $dateLabel,
        int $summaryTotalTours,
        int $summaryTotalBookings,
        int $summaryTotalPax,
        int $summaryWhatsappEligible,
        int $summaryOptedOut,
        int $summaryNoPhone
    ): string {
        if ($bookings->isEmpty() && $summaryTotalBookings === 0) {
            return "✅ No tours scheduled for tomorrow.";
        }

        $grouped     = $bookings->groupBy('tour_title');
        $totalTours  = $grouped->count();
        $totalGuests = $bookings->sum('number_of_people');

        $lines   = [];
        $lines[] = "📅 <b>Tomorrow's Tours — {$dateLabel}</b>";

        if ($bookings->isEmpty()) {
            $lines[] = '';
            $lines[] = "<i>All bookings are marked do-not-remind.</i>";
        } else {
            foreach ($grouped as $tourName => $tourBookings) {
                $lines[] = '';
                $lines[] = "🏕 <b>{$tourName}</b>";
                foreach ($tourBookings as $b) {
                    $fullName = trim("{$b->first_name} {$b->last_name}");
                    $pax      = (int) $b->number_of_people;
                    $pickup   = $b->pickup_location ?: 'TBD';

                    $pickupTime = $b->booking_pickup_time
                        ?: ($b->tour_pickup_time
                            ? substr($b->tour_pickup_time, 0, 5)
                            : '08:30');

                    $ref    = $b->booking_number ? " | Ref: {$b->booking_number}" : '';
                    $source = $b->booking_source ? " [{$b->booking_source}]" : '';

                    $lines[] = "• {$fullName} ({$pax} pax) — pickup: {$pickup} at {$pickupTime}{$ref}{$source}";
                }
            }
        }

        // ── Pickup Groups block ────────────────────────────────────────────────
        if ($bookings->isNotEmpty()) {
            // Group by pickup_location → pickup_time (sort by time ascending)
            $byLocation = $bookings->groupBy(function ($b) {
                return $b->pickup_location ?: 'TBD';
            })->sortKeys();

            $lines[] = '';
            $lines[] = "🚐 <b>Pickup Groups:</b>";

            foreach ($byLocation as $location => $locationBookings) {
                // Sort by pickup_time ascending within each location
                $byTime = $locationBookings->groupBy(function ($b) {
                    return $b->booking_pickup_time
                        ?: ($b->tour_pickup_time
                            ? substr($b->tour_pickup_time, 0, 5)
                            : '08:30');
                })->sortKeys();

                foreach ($byTime as $time => $timeBookings) {
                    $lines[] = '';
                    $lines[] = "📍 {$location} — {$time}";
                    foreach ($timeBookings as $b) {
                        $fullName  = trim("{$b->first_name} {$b->last_name}");
                        $pax       = (int) $b->number_of_people;
                        $tourShort = $b->tour_title;
                        $lines[]   = "  • {$fullName} ({$pax} pax) — {$tourShort}";
                    }
                }
            }
        }

        // ── Driver Briefing Draft block ────────────────────────────────────────
        if ($bookings->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📋 <b>DRIVER BRIEFING DRAFT</b>';
            $lines[] = '<i>(copy &amp; send manually)</i>';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '';
            $lines[] = "🚗 <b>Tomorrow's Tour Briefing — {$dateLabel}</b>";

            $groupedForBriefing = $bookings->groupBy('tour_title');
            foreach ($groupedForBriefing as $tourName => $tourBookings) {
                $firstBooking = $tourBookings->first();

                $pickupTime = $firstBooking->booking_pickup_time
                    ?: ($firstBooking->tour_pickup_time
                        ? substr($firstBooking->tour_pickup_time, 0, 5)
                        : '08:30');
                $pickupLocation = $firstBooking->pickup_location ?: 'TBD';

                $lines[] = '';
                $lines[] = "🏕 <b>{$tourName}</b>";
                $lines[] = "🕗 {$pickupTime} | 📍 {$pickupLocation}";
                $lines[] = '';
                $lines[] = 'Guests:';

                $tourPax = 0;
                $specialRequests = [];
                foreach ($tourBookings as $b) {
                    $fullName = trim("{$b->first_name} {$b->last_name}");
                    $pax      = (int) $b->number_of_people;
                    $tourPax += $pax;
                    $flag     = $this->countryFlag($b->country ?? '');
                    $phone    = trim($b->phone ?? '');
                    $phoneStr = !empty($phone) ? "📞 {$phone}" : 'no phone';
                    $lines[]  = "• {$fullName} {$flag} — {$pax} pax | {$phoneStr}";

                    $sr = trim($b->special_requests ?? '');
                    if (!empty($sr)) {
                        $specialRequests[] = "⚠️ {$fullName}: {$sr}";
                    }
                }

                $lines[] = '';
                $lines[] = "Total pax: {$tourPax}";

                if (!empty($specialRequests)) {
                    $lines[] = '';
                    foreach ($specialRequests as $sr) {
                        $lines[] = $sr;
                    }
                }
            }

            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
        }

        // ── Summary block ──────────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = "📊 <b>Summary:</b>";
        $lines[] = "• Tours tomorrow: {$summaryTotalTours}";
        $lines[] = "• Total bookings: {$summaryTotalBookings}";
        $lines[] = "• Total pax: {$summaryTotalPax}";
        $lines[] = "• WhatsApp-eligible: {$summaryWhatsappEligible} (have valid phone)";
        $lines[] = "• Opted out: {$summaryOptedOut} (do_not_remind)";
        $lines[] = "• No phone: {$summaryNoPhone}";

        $lines[] = '';
        $lines[] = "<i>Total: {$totalTours} " . ($totalTours === 1 ? 'tour' : 'tours') . ", {$totalGuests} " . ($totalGuests === 1 ? 'guest' : 'guests') . "</i>";

        return implode("\n", $lines);
    }

    private function buildGuestMessage(
        string  $firstName,
        string  $tourName,
        string  $dateLabel,
        ?string $pickupLocation,
        string  $pickupTime = '08:30'
    ): string {
        $pickup = $pickupLocation ?: 'your hotel';

        return implode("\n", [
            "Hi {$firstName}! 👋",
            "",
            "Just a friendly reminder that your tour \"{$tourName}\" is tomorrow.",
            "",
            "📍 Pickup: {$pickup}",
            "🕗 Time: {$pickupTime}",
            "",
            "Please be ready 5 minutes early.",
            "If you have any questions, feel free to reply here.",
            "",
            "— Jahongir Travel",
        ]);
    }

    // =========================================================================
    // Phone normalisation (E.164)
    // =========================================================================

    /**
     * Normalise a raw phone string to E.164 (+XXXXXXXXXXX) or return null if invalid.
     */
    private function normalizePhone(string $raw): ?string
    {
        // Strip common formatting characters
        $stripped = preg_replace('/[\s\-().]+/', '', $raw);

        // 00... → +...
        if (str_starts_with($stripped, '00')) {
            $stripped = '+' . substr($stripped, 2);
        }

        // If still no leading +, add one
        if (!str_starts_with($stripped, '+')) {
            $stripped = '+' . $stripped;
        }

        // Count digits only (excluding the leading +)
        $digitsOnly = preg_replace('/\D/', '', $stripped);
        $digitCount = strlen($digitsOnly);

        if ($digitCount < 7 || $digitCount > 15) {
            return null;
        }

        return $stripped;
    }

    // =========================================================================
    // Audit logging helpers
    // =========================================================================

    private function logWhatsApp(
        object  $booking,
        ?string $phone,
        string  $status,
        ?string $errorMessage,
        string  $scheduledForDate,
        bool    $dryRun
    ): void {
        if ($dryRun) {
            return; // don't write DB rows in dry-run
        }

        DB::table('tour_reminder_logs')->insert([
            'booking_id'         => $booking->id,
            'guest_id'           => $booking->guest_id ?? null,
            'channel'            => 'whatsapp',
            'scheduled_for_date' => $scheduledForDate,
            'phone'              => $phone,
            'status'             => $status,
            'error_message'      => $errorMessage,
            'reminded_at'        => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function logTelegram(string $status, string $scheduledForDate): void
    {
        DB::table('tour_reminder_logs')->insert([
            'booking_id'         => null,
            'guest_id'           => null,
            'channel'            => 'telegram',
            'scheduled_for_date' => $scheduledForDate,
            'phone'              => null,
            'status'             => $status,
            'error_message'      => null,
            'reminded_at'        => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    // =========================================================================
    // Telegram dispatch
    // =========================================================================

    /**
     * @return bool true on success (job dispatched), false on missing config
     */
    private function sendTelegram(string $text): bool
    {
        if ($this->ownerChatId === 0) {
            Log::warning('TourSendReminders: owner chat ID not configured');
            $this->warn('Telegram credentials not configured — skipping.');
            return false;
        }

        SendTelegramNotificationJob::dispatch(
            'owner-alert',
            'sendMessage',
            [
                'chat_id'    => $this->ownerChatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]
        );

        return true;
    }

    // =========================================================================
    // Utility
    // =========================================================================

    private function indent(string $text, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);
        return $pad . implode("\n{$pad}", explode("\n", $text));
    }

    private function countryFlag(string $country): string
    {
        $map = [
            // Asia-Pacific
            'japan'               => '🇯🇵',
            'south korea'         => '🇰🇷',
            'korea'               => '🇰🇷',
            'china'               => '🇨🇳',
            'hong kong'           => '🇭🇰',
            'hk'                  => '🇭🇰',
            'taiwan'              => '🇹🇼',
            'singapore'           => '🇸🇬',
            'thailand'            => '🇹🇭',
            'vietnam'             => '🇻🇳',
            'indonesia'           => '🇮🇩',
            'malaysia'            => '🇲🇾',
            'india'               => '🇮🇳',
            'australia'           => '🇦🇺',
            'new zealand'         => '🇳🇿',
            // Europe
            'france'              => '🇫🇷',
            'germany'             => '🇩🇪',
            'italy'               => '🇮🇹',
            'spain'               => '🇪🇸',
            'united kingdom'      => '🇬🇧',
            'uk'                  => '🇬🇧',
            'great britain'       => '🇬🇧',
            'sweden'              => '🇸🇪',
            'norway'              => '🇳🇴',
            'finland'             => '🇫🇮',
            'denmark'             => '🇩🇰',
            'netherlands'         => '🇳🇱',
            'holland'             => '🇳🇱',
            'belgium'             => '🇧🇪',
            'switzerland'         => '🇨🇭',
            'austria'             => '🇦🇹',
            'poland'              => '🇵🇱',
            'czech republic'      => '🇨🇿',
            'czechia'             => '🇨🇿',
            'hungary'             => '🇭🇺',
            'portugal'            => '🇵🇹',
            'greece'              => '🇬🇷',
            'romania'             => '🇷🇴',
            'ukraine'             => '🇺🇦',
            'russia'              => '🇷🇺',
            // Americas
            'united states'       => '🇺🇸',
            'usa'                 => '🇺🇸',
            'us'                  => '🇺🇸',
            'canada'              => '🇨🇦',
            'brazil'              => '🇧🇷',
            'argentina'           => '🇦🇷',
            'mexico'              => '🇲🇽',
            // Middle East & Africa
            'israel'              => '🇮🇱',
            'turkey'              => '🇹🇷',
            'iran'                => '🇮🇷',
            'saudi arabia'        => '🇸🇦',
            'uae'                 => '🇦🇪',
            'south africa'        => '🇿🇦',
            // CIS
            'kazakhstan'          => '🇰🇿',
            'uzbekistan'          => '🇺🇿',
            'kyrgyzstan'          => '🇰🇬',
            'tajikistan'          => '🇹🇯',
            'azerbaijan'          => '🇦🇿',
            'georgia'             => '🇬🇪',
            'armenia'             => '🇦🇲',
        ];

        $key = strtolower(trim($country));
        return $map[$key] ?? '🌍';
    }
}
