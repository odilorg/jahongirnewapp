<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Messaging\WhatsAppSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Daily tour reminders — 3 phases:
 *   Phase 1: Telegram staff summary of tomorrow's bookings
 *   Phase 2: WhatsApp guest reminders with pickup details
 *   Phase 3: Telegram DMs to assigned drivers/guides
 *
 * Repointed from legacy `bookings+guests+tours` to `booking_inquiries`
 * in Phase 9.2. WhatsApp now uses WhatsAppSender (wa-api) instead of
 * wa-queue.
 */
class TourSendReminders extends Command
{
    protected $signature   = 'tour:send-reminders {--dry-run : Print output without sending}';
    protected $description = 'Send daily tour reminders — Telegram staff + WhatsApp guest + Telegram driver/guide';

    private int $ownerChatId;
    private string $ownerBotToken;
    private string $driverGuideBotToken;

    public function __construct(
        private WhatsAppSender $whatsApp,
    ) {
        parent::__construct();
        $this->ownerChatId        = (int) config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '0'));
        $this->ownerBotToken      = (string) config('services.ops_bot.token', '');
        $this->driverGuideBotToken = (string) env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE', '');
    }

    public function handle(): int
    {
        $dryRun    = $this->option('dry-run');
        $tz        = 'Asia/Tashkent';
        $tomorrow  = Carbon::now($tz)->addDay()->toDateString();
        $dateLabel = Carbon::now($tz)->addDay()->format('D, d M Y');

        if ($dryRun) {
            $this->info('[DRY-RUN] No messages will be sent.');
        }

        $this->info("Fetching confirmed inquiries for tomorrow: {$tomorrow}");

        $inquiries = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->where('travel_date', $tomorrow)
            ->with(['driver', 'guide', 'tourProduct'])
            ->orderBy('tour_slug')
            ->orderBy('customer_name')
            ->get();

        if ($inquiries->isEmpty()) {
            $this->info('No confirmed bookings for tomorrow.');

            return self::SUCCESS;
        }

        $this->info("Found {$inquiries->count()} bookings for tomorrow.");

        // ── Phase 1: Telegram Staff Summary ─────────────────────────────────
        $this->sendStaffSummary($inquiries, $dateLabel, $dryRun);

        // ── Phase 2: WhatsApp Guest Reminders ───────────────────────────────
        $this->sendGuestReminders($inquiries, $dateLabel, $tomorrow, $dryRun);

        // ── Phase 3: Driver/Guide Telegram DMs ──────────────────────────────
        $this->sendDriverGuideNotifications($inquiries, $dateLabel, $tomorrow, $dryRun);

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Phase 1 — Staff Telegram Summary
    // ═══════════════════════════════════════════════════════════════════════

    private function sendStaffSummary($inquiries, string $dateLabel, bool $dryRun): void
    {
        $totalPax = $inquiries->sum(fn ($i) => $i->people_adults + $i->people_children);

        $lines = [
            "📋 <b>Tomorrow's Tours — {$dateLabel}</b>",
            "<b>{$inquiries->count()}</b> bookings · <b>{$totalPax}</b> guests",
            '',
        ];

        foreach ($inquiries->groupBy(fn ($i) => $i->tourProduct?->title ?? $i->tour_name_snapshot) as $tour => $group) {
            $tourClean = htmlspecialchars(mb_substr($tour, 0, 50), ENT_QUOTES, 'UTF-8');
            $lines[] = "🗺 <b>{$tourClean}</b> ({$group->count()} bookings)";

            foreach ($group as $inquiry) {
                $pax     = $inquiry->people_adults + $inquiry->people_children;
                $name    = htmlspecialchars($inquiry->customer_name, ENT_QUOTES, 'UTF-8');
                $phone   = htmlspecialchars($inquiry->customer_phone, ENT_QUOTES, 'UTF-8');
                $pickup  = $inquiry->pickup_time ?? '09:00';
                $source  = strtoupper($inquiry->source);

                // Driver TG status
                $driverLabel = '—';
                if ($inquiry->driver) {
                    $driverName = htmlspecialchars($inquiry->driver->full_name, ENT_QUOTES, 'UTF-8');
                    $driverLabel = $inquiry->driver->telegram_chat_id
                        ? "✅ {$driverName}"
                        : "⚠️ {$driverName} (NO TG)";
                }

                // Guide TG status
                $guideLabel = '';
                if ($inquiry->guide) {
                    $guideName = htmlspecialchars($inquiry->guide->full_name, ENT_QUOTES, 'UTF-8');
                    $guideLabel = $inquiry->guide->telegram_chat_id
                        ? " · 🧭 ✅ {$guideName}"
                        : " · 🧭 ⚠️ {$guideName} (NO TG)";
                }

                $lines[] = "  👤 {$name} ({$pax} pax) · {$pickup} · 📱 {$phone}";
                $lines[] = "     🚗 {$driverLabel}{$guideLabel} · [{$source}] <code>{$inquiry->reference}</code>";
            }
            $lines[] = '';
        }

        $message = implode("\n", $lines);

        $this->info('--- Telegram Staff Message ---');
        $this->line(strip_tags($message));
        $this->info('------------------------------');

        if (! $dryRun) {
            $ok = $this->sendTelegram($message, $this->ownerBotToken, $this->ownerChatId);
            $this->info($ok ? '✅ Telegram staff alert sent.' : '⚠ Telegram staff alert failed.');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Phase 2 — WhatsApp Guest Reminders
    // ═══════════════════════════════════════════════════════════════════════

    private function sendGuestReminders($inquiries, string $dateLabel, string $tomorrow, bool $dryRun): void
    {
        $this->info('--- Phase 2: WhatsApp Guest Reminders ---');

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;
        $phonesThisRun = [];

        foreach ($inquiries as $inquiry) {
            $phone = $this->whatsApp->normalizePhone($inquiry->customer_phone);

            if (! $phone) {
                $this->warn("  ⚠ Skipping {$inquiry->customer_name} — no valid phone.");
                $skipped++;

                continue;
            }

            if (isset($phonesThisRun[$phone])) {
                $this->warn("  ⚠ Skipping {$inquiry->customer_name} — duplicate phone.");
                $skipped++;

                continue;
            }

            // Idempotency via tour_reminder_logs
            $alreadySent = DB::table('tour_reminder_logs')
                ->where('booking_inquiry_id', $inquiry->id)
                ->where('channel', 'whatsapp')
                ->where('scheduled_for_date', $tomorrow)
                ->exists();

            if ($alreadySent) {
                $this->warn("  ⚠ Skipping {$inquiry->customer_name} — already sent for {$tomorrow}.");
                $skipped++;

                continue;
            }

            $pickupTime = $inquiry->pickup_time ?? '09:00';
            $tourTitle  = $inquiry->tourProduct?->title
                ?? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) $inquiry->tour_name_snapshot);
            $driverName  = $inquiry->driver?->full_name;
            $driverPhone = $inquiry->driver?->phone01;
            $guideName   = $inquiry->guide?->full_name;
            $guidePhone  = $inquiry->guide?->phone01;

            $message = $this->buildGuestMessage(
                $this->firstName($inquiry->customer_name),
                $tourTitle,
                $dateLabel,
                $inquiry->pickup_point,
                $pickupTime,
                $driverName,
                $driverPhone,
                $guideName,
                $guidePhone,
            );

            $this->info("  📱 WhatsApp → {$phone} ({$inquiry->customer_name})");
            $phonesThisRun[$phone] = true;

            if ($dryRun) {
                $sent++;

                continue;
            }

            $result = $this->whatsApp->send($phone, $message);

            $status = $result->ok ? 'sent' : 'failed';
            DB::table('tour_reminder_logs')->insert([
                'booking_inquiry_id' => $inquiry->id,
                'channel'            => 'whatsapp',
                'phone'              => $phone,
                'status'             => $status,
                'error_message'      => $result->ok ? null : $result->error,
                'scheduled_for_date' => $tomorrow,
                'reminded_at'        => now(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            if ($result->ok) {
                $sent++;
                $this->info("     ✅ Sent");
            } else {
                $failed++;
                $this->error("     ❌ Failed: {$result->error}");
            }
        }

        $this->info("WhatsApp summary: {$sent} sent, {$skipped} skipped, {$failed} failed.");
    }

    private function buildGuestMessage(
        string $firstName,
        string $tourTitle,
        string $dateLabel,
        ?string $pickup,
        string $pickupTime,
        ?string $driverName,
        ?string $driverPhone,
        ?string $guideName,
        ?string $guidePhone,
    ): string {
        $lines = [
            "Hi {$firstName}! 👋",
            '',
            "Just a friendly reminder — your *{$tourTitle}* is tomorrow! 🎉",
            '',
            "📅 {$dateLabel}",
            "⏰ Pickup: {$pickupTime}",
        ];

        if (filled($pickup)) {
            $lines[] = "🏨 Location: {$pickup}";
        }

        if ($driverName) {
            $lines[] = "🚗 Driver: {$driverName}" . ($driverPhone ? " ({$driverPhone})" : '');
        }

        if ($guideName) {
            $lines[] = "🧭 Guide: {$guideName}" . ($guidePhone ? " ({$guidePhone})" : '');
        }

        $lines[] = '';
        $lines[] = 'Have a wonderful trip! 🌟';
        $lines[] = '— Jahongir Travel';

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Phase 3 — Driver/Guide Telegram DMs
    // ═══════════════════════════════════════════════════════════════════════

    private function sendDriverGuideNotifications($inquiries, string $dateLabel, string $tomorrow, bool $dryRun): void
    {
        $this->info('--- Phase 3: Driver/Guide Telegram DMs ---');

        if (empty($this->driverGuideBotToken)) {
            $this->warn('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE not set — skipping.');

            return;
        }

        // Collect unique assigned drivers/guides
        $driverIds = $inquiries->pluck('driver_id')->filter()->unique();
        $guideIds  = $inquiries->pluck('guide_id')->filter()->unique();

        if ($driverIds->isEmpty() && $guideIds->isEmpty()) {
            $this->info('No drivers or guides assigned to tomorrow\'s bookings.');

            return;
        }

        // Fetch ALL assigned drivers/guides (including those without TG)
        $allDrivers = DB::table('drivers')->whereIn('id', $driverIds)->get()->keyBy('id');
        $allGuides  = DB::table('guides')->whereIn('id', $guideIds)->get()->keyBy('id');

        // Only those with telegram_chat_id get DMs
        $drivers = $allDrivers->filter(fn ($d) => filled($d->telegram_chat_id));
        $guides  = $allGuides->filter(fn ($g) => filled($g->telegram_chat_id));

        // Track who was NOT notifiable — report to operator
        $failures = [];

        foreach ($allDrivers as $d) {
            if (empty($d->telegram_chat_id)) {
                $failures[] = "🚗 Driver: {$d->first_name} {$d->last_name} — NO TELEGRAM, notify manually";
            }
        }
        foreach ($allGuides as $g) {
            if (empty($g->telegram_chat_id)) {
                $failures[] = "🧭 Guide: {$g->first_name} {$g->last_name} — NO TELEGRAM, notify manually";
            }
        }

        $notified = [];

        // Notify each driver once with all their assigned bookings
        foreach ($driverIds as $driverId) {
            if (! isset($drivers[$driverId])) {
                continue;
            }
            $driver = $drivers[$driverId];
            $key    = "driver_{$driverId}";

            if (isset($notified[$key])) {
                continue;
            }

            $alreadySent = DB::table('tour_reminder_logs')
                ->where('channel', 'telegram_driver')
                ->where('scheduled_for_date', $tomorrow)
                ->where('guest_id', $driverId)
                ->exists();

            if ($alreadySent) {
                $this->warn("  ⚠ Driver {$driver->first_name} already notified for {$tomorrow}.");
                $notified[$key] = true;

                continue;
            }

            $assigned = $inquiries->where('driver_id', $driverId);
            $message  = $this->buildDriverMessage($assigned, $dateLabel, $driver);

            $this->info("  🚗 Telegram → Driver: {$driver->first_name} {$driver->last_name}");

            if (! $dryRun) {
                $ok = $this->sendTelegram($message, $this->driverGuideBotToken, (int) $driver->telegram_chat_id);

                DB::table('tour_reminder_logs')->insert([
                    'channel'            => 'telegram_driver',
                    'guest_id'           => $driverId,
                    'status'             => $ok ? 'sent' : 'failed',
                    'scheduled_for_date' => $tomorrow,
                    'reminded_at'        => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                if ($ok) {
                    $this->info('     ✅ Sent');
                } else {
                    $failures[] = "🚗 Driver: {$driver->first_name} {$driver->last_name} — TG send FAILED";
                    $this->error('     ❌ Failed');
                }
            }

            $notified[$key] = true;
        }

        // Same pattern for guides
        foreach ($guideIds as $guideId) {
            if (! isset($guides[$guideId])) {
                continue;
            }
            $guide = $guides[$guideId];
            $key   = "guide_{$guideId}";

            if (isset($notified[$key])) {
                continue;
            }

            $alreadySent = DB::table('tour_reminder_logs')
                ->where('channel', 'telegram_guide')
                ->where('scheduled_for_date', $tomorrow)
                ->where('guest_id', $guideId)
                ->exists();

            if ($alreadySent) {
                $notified[$key] = true;

                continue;
            }

            $assigned = $inquiries->where('guide_id', $guideId);
            $message  = $this->buildGuideMessage($assigned, $dateLabel, $guide);

            $this->info("  🧭 Telegram → Guide: {$guide->first_name} {$guide->last_name}");

            if (! $dryRun) {
                $ok = $this->sendTelegram($message, $this->driverGuideBotToken, (int) $guide->telegram_chat_id);

                DB::table('tour_reminder_logs')->insert([
                    'channel'            => 'telegram_guide',
                    'guest_id'           => $guideId,
                    'status'             => $ok ? 'sent' : 'failed',
                    'scheduled_for_date' => $tomorrow,
                    'reminded_at'        => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                if ($ok) {
                    $this->info('     ✅ Sent');
                } else {
                    $failures[] = "🧭 Guide: {$guide->first_name} {$guide->last_name} — TG send FAILED";
                    $this->error('     ❌ Failed');
                }
            }

            $notified[$key] = true;
        }

        // Alert operator about any driver/guide who could NOT be notified
        if (! empty($failures) && ! $dryRun) {
            $alertLines = [
                '⚠️ <b>Driver/Guide notification issues — ' . $dateLabel . '</b>',
                '',
                'The following people were NOT reached via Telegram.',
                '<b>Please notify them manually:</b>',
                '',
            ];
            foreach ($failures as $f) {
                $alertLines[] = $f;
            }

            $this->sendTelegram(implode("\n", $alertLines), $this->ownerBotToken, $this->ownerChatId);
            $this->warn('⚠ Sent failure alert to operator for ' . count($failures) . ' unnotified driver/guide(s).');
        }
    }

    private function buildDriverMessage($inquiries, string $dateLabel, $driver): string
    {
        $lines = [
            "🚗 <b>Ertangi turlar — {$dateLabel}</b>",
            '',
        ];

        foreach ($inquiries as $inquiry) {
            $pax     = $inquiry->people_adults + $inquiry->people_children;
            $tour    = $inquiry->tourProduct?->title ?? $inquiry->tour_name_snapshot;
            $pickup  = $inquiry->pickup_point ?? 'belgilanmagan';
            $time    = $inquiry->pickup_time ?? '09:00';
            $phone   = $inquiry->customer_phone;

            $lines[] = "👤 <b>{$inquiry->customer_name}</b> ({$pax} kishi)";
            $lines[] = "🗺 {$tour}";
            $lines[] = "⏰ {$time} · 🏨 {$pickup}";
            $lines[] = "📱 {$phone}";

            // Show guide info so driver knows who to coordinate with
            if ($inquiry->guide) {
                $lines[] = "🧭 Gid: {$inquiry->guide->full_name} · {$inquiry->guide->phone01}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function buildGuideMessage($inquiries, string $dateLabel, $guide): string
    {
        $lines = [
            "🧭 <b>Tomorrow's Tours — {$dateLabel}</b>",
            '',
        ];

        foreach ($inquiries as $inquiry) {
            $pax     = $inquiry->people_adults + $inquiry->people_children;
            $tour    = $inquiry->tourProduct?->title ?? $inquiry->tour_name_snapshot;
            $pickup  = $inquiry->pickup_point ?? '—';
            $time    = $inquiry->pickup_time ?? '09:00';

            $lines[] = "👤 <b>{$inquiry->customer_name}</b> ({$pax} pax)";
            $lines[] = "🗺 {$tour}";
            $lines[] = "⏰ {$time} · 🏨 {$pickup}";
            $lines[] = "📱 {$inquiry->customer_phone}";

            // Show driver info so guide knows who to coordinate with
            if ($inquiry->driver) {
                $lines[] = "🚗 Driver: {$inquiry->driver->full_name} · {$inquiry->driver->phone01}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function sendTelegram(string $message, string $token, int $chatId): bool
    {
        if (empty($token) || $chatId === 0) {
            return false;
        }

        try {
            $response = Http::timeout(5)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id'                  => $chatId,
                    'text'                     => $message,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('TourSendReminders: Telegram send failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return $parts[0] ?? $fullName;
    }
}
