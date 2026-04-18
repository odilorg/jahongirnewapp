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

            $message = $this->buildGuestMessage($inquiry, $dateLabel);

            $this->info("  📱 WhatsApp → {$phone} ({$inquiry->customer_name})");
            $phonesThisRun[$phone] = true;

            if ($dryRun) {
                $sent++;

                continue;
            }

            $result = $this->whatsApp->send($phone, $message);

            $status = $result->success ? 'sent' : 'failed';
            DB::table('tour_reminder_logs')->insert([
                'booking_inquiry_id' => $inquiry->id,
                'channel'            => 'whatsapp',
                'phone'              => $phone,
                'status'             => $status,
                'error_message'      => $result->success ? null : $result->error,
                'scheduled_for_date' => $tomorrow,
                'reminded_at'        => now(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            if ($result->success) {
                $sent++;
                $this->info("     ✅ Sent");
            } else {
                $failed++;
                $this->error("     ❌ Failed: {$result->error}");
            }
        }

        $this->info("WhatsApp summary: {$sent} sent, {$skipped} skipped, {$failed} failed.");
    }

    /**
     * Phase 25 — rewritten guest reminder message.
     *   - Context-aware pickup block (private hotel / group Samarkand / Bukhara dropoff)
     *   - Primary contact = guide if assigned, else driver
     *   - Car line only when driver is primary contact and car data exists
     *   - Packing/meals from config by tour slug
     *   - Weather advisory only if fetch succeeds; silent on failure
     *   - Correct support number +998 91 555 08 08 via config
     */
    private function buildGuestMessage(\App\Models\BookingInquiry $inquiry, string $dateLabel): string
    {
        $firstName = $this->firstName($inquiry->customer_name);

        $tourTitle = $inquiry->tourProduct?->title
            ?? preg_replace('/\s*\|\s*Jahongir\s+Travel\s*$/iu', '', (string) $inquiry->tour_name_snapshot);
        $direction = $inquiry->tourProductDirection?->name;
        $headline  = $direction ? "*{$tourTitle}* ({$direction})" : "*{$tourTitle}*";

        $pickupTime  = $inquiry->pickup_time ? substr((string) $inquiry->pickup_time, 0, 5) : '09:00';
        $hoursUntil  = $this->hoursUntilPickup($inquiry, $pickupTime);

        $lines = [
            "Hi {$firstName}! 👋",
            '',
            "Your {$headline} is tomorrow 🎉",
            '',
            'Everything is arranged and ready for you 👍',
            '',
            "📅 {$dateLabel}",
            "⏰ Pickup: {$pickupTime}" . ($hoursUntil ? " (in ~{$hoursUntil}h)" : ''),
        ];

        // Pickup block — context-aware
        foreach ($this->buildPickupBlockLines($inquiry) as $l) {
            $lines[] = $l;
        }

        // Primary contact (guide-first, driver fallback)
        $lines[] = '';
        foreach ($this->buildContactBlockLines($inquiry) as $l) {
            $lines[] = $l;
        }

        // Packing list
        $slug = $inquiry->tourProduct?->slug ?? 'default';
        $packing = config("tour_experience.packing_lists.{$slug}")
            ?? config('tour_experience.packing_lists.default', []);
        if (! empty($packing)) {
            $lines[] = '';
            $lines[] = '🎒 What to bring:';
            foreach ($packing as $item) {
                $lines[] = "• {$item}";
            }
        }

        // Weather (silent on failure)
        $weatherBlock = $this->buildWeatherBlock($inquiry);
        if ($weatherBlock) {
            $lines[] = '';
            foreach ($weatherBlock as $l) $lines[] = $l;
        }

        // Meals
        $meals = config("tour_experience.meal_plans.{$slug}")
            ?? config('tour_experience.meal_plans.default');
        if (filled($meals)) {
            $lines[] = '';
            $lines[] = "🍽 {$meals}";
        }

        // Support
        $opsWhatsapp = config('tour_experience.ops_whatsapp', '+998 91 555 08 08');
        $lines[] = '';
        $lines[] = "🆘 Need help? WhatsApp us anytime: {$opsWhatsapp}";

        $lines[] = '';
        $lines[] = 'Have an amazing trip! 🌟';
        $lines[] = '— Jahongir Travel';

        return implode("\n", $lines);
    }

    /**
     * @return array<string>
     */
    private function buildPickupBlockLines(\App\Models\BookingInquiry $inquiry): array
    {
        $lines = [];
        $pickup = $inquiry->pickup_point;
        $isGroupInSamarkand = $inquiry->tour_type === 'group'
            && (blank($pickup) || in_array($pickup, ['Samarkand', 'Gur Emir Mausoleum'], true));

        if ($isGroupInSamarkand) {
            $lines[] = '🏛 Meeting point: Gur Emir Mausoleum, Samarkand';
            $lines[] = '📍 https://maps.google.com/?q=Gur+Emir+Mausoleum+Samarkand';
            $lines[] = 'You will be met downstairs next to the entrance portal';
        } elseif (filled($pickup)) {
            $lines[] = "🏨 {$pickup}";
            $lines[] = '📍 https://maps.google.com/?q=' . rawurlencode($pickup);
            $lines[] = 'You will be met at the reception';
        }

        // Drop-off: operator-set value wins; Bukhara default only if left blank.
        // Operators sometimes override with a specific hotel — must echo verbatim
        // with a map link so guests know exactly where tour ends.
        $dropoff = trim((string) ($inquiry->dropoff_point ?? ''));
        $directionName = strtolower((string) $inquiry->tourProductDirection?->name);

        if (filled($dropoff)) {
            $lines[] = "📍 Drop-off: {$dropoff}";
            $lines[] = '📍 https://maps.google.com/?q=' . rawurlencode($dropoff);
        } elseif (str_contains($directionName, 'bukhara') && stripos($directionName, '→ samarkand') === false) {
            $lines[] = '📍 Drop-off: Lyabi Hauz area, Bukhara';
        }

        return $lines;
    }

    /**
     * Guide-first, driver fallback. Never shows empty labels.
     *
     * @return array<string>
     */
    private function buildContactBlockLines(\App\Models\BookingInquiry $inquiry): array
    {
        $lines = [];

        if ($inquiry->guide && $inquiry->guide->full_name) {
            $name  = $inquiry->guide->full_name;
            $phone = $inquiry->guide->phone01;
            $lines[] = "🧭 Your guide: {$name}";
            if ($phone) $lines[] = "📱 {$phone}";
            return $lines;
        }

        if ($inquiry->driver && $inquiry->driver->full_name) {
            $name  = $inquiry->driver->full_name;
            $phone = $inquiry->driver->phone01;
            $lines[] = "🚗 Your driver: {$name}";
            if ($phone) $lines[] = "📱 {$phone}";

            $carLine = $this->buildCarLine($inquiry->driver);
            if ($carLine) $lines[] = "🚐 {$carLine}";
        }

        return $lines;
    }

    private function buildCarLine(\App\Models\Driver $driver): ?string
    {
        $car = $driver->cars()->first();
        if (! $car) return null;

        $parts = [];
        if (filled($car->color)) $parts[] = ucfirst((string) $car->color);
        if (filled($car->brand_name)) $parts[] = $car->brand_name;

        $left = trim(implode(' ', $parts));
        if ($left === '' && blank($car->plate_number)) return null;

        if ($left === '') return (string) $car->plate_number;
        if (blank($car->plate_number)) return $left;

        return "{$left} · {$car->plate_number}";
    }

    private function hoursUntilPickup(\App\Models\BookingInquiry $inquiry, string $pickupTime): ?int
    {
        try {
            $date = $inquiry->travel_date ? $inquiry->travel_date->format('Y-m-d') : null;
            if (! $date) return null;
            $dt = \Carbon\Carbon::parse("{$date} {$pickupTime}", 'Asia/Tashkent');
            $hours = (int) round(now('Asia/Tashkent')->diffInMinutes($dt, false) / 60);
            return $hours > 0 ? $hours : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * wttr.in free weather lookup. Gracefully returns empty on any failure.
     *
     * @return array<string>
     */
    private function buildWeatherBlock(\App\Models\BookingInquiry $inquiry): array
    {
        $slug = $inquiry->tourProduct?->slug ?? 'default';
        $location = config("tour_experience.weather_locations.{$slug}")
            ?? config('tour_experience.weather_locations.default');
        if (! $location) return [];

        try {
            $cacheKey = "wttr:tomorrow:{$location}";
            $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($location) {
                $response = \Illuminate\Support\Facades\Http::timeout(3)
                    ->get("https://wttr.in/" . rawurlencode($location), ['format' => 'j1']);
                return $response->successful() ? $response->json() : null;
            });

            if (! $data || empty($data['weather'][1])) return [];
            $tomorrow = $data['weather'][1];
            $maxC = $tomorrow['maxtempC'] ?? null;
            $minC = $tomorrow['mintempC'] ?? null;
            if (! $maxC || ! $minC) return [];

            return [
                "🌤 Expected around {$location}:",
                "☀️ ~{$maxC}°C daytime",
                "🌙 ~{$minC}°C at night",
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('Weather fetch failed — omitting', [
                'location' => $location,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
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
