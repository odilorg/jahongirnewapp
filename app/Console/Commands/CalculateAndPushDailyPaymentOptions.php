<?php

namespace App\Console\Commands;

use App\Models\Beds24Booking;
use App\Models\DailyExchangeRate;
use App\Services\Beds24BookingService;
use App\Services\BookingPaymentOptionsService;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every morning at 07:00 Tashkent time.
 *
 * Fix 1 — covers next 7 days (not just today+tomorrow) so admin can print
 *          registration forms well in advance.
 *
 * Fix 2 — for each target date, fetches bookings directly from Beds24 API
 *          so bookings not yet in the local DB (e.g. arrived via a different
 *          channel, or webhook not yet processed) are also covered.
 *
 * Flow:
 *   1. Fetch CBU rates for USD, EUR, RUB
 *   2. Upsert daily_exchange_rates for target date
 *   3. Collect bookings from local DB (fast) + Beds24 API (authoritative)
 *   4. Deduplicate by beds24_booking_id
 *   5. For each booking with a USD amount, calculate and push infoItems
 */
class CalculateAndPushDailyPaymentOptions extends Command
{
    protected $signature = 'fx:push-payment-options
                            {--date= : Target start date (Y-m-d). Defaults to today.}
                            {--days=7 : Number of days ahead to cover (default: 7).}
                            {--usd-rate= : Override the USD/UZS rate (skips API fetch).}
                            {--eur-margin= : Override EUR safety margin (default: 200).}
                            {--rub-margin= : Override RUB safety margin (default: 20).}
                            {--dry-run : Calculate and log without writing to Beds24.}';

    protected $description = 'Fetch exchange rates, calculate UZS/EUR/RUB amounts, push to Beds24 infoItems (covers next 7 days)';

    // Beds24 property IDs to query when syncing from API
    private const PROPERTY_IDS = ['41097', '172793'];

    public function __construct(
        private readonly ExchangeRateService          $fxService,
        private readonly BookingPaymentOptionsService $calcService,
        private readonly Beds24BookingService         $beds24Service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startDate = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : today()->toDateString();

        $days     = max(1, (int) $this->option('days'));
        $isDryRun = (bool) $this->option('dry-run');

        // Build date window
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = Carbon::parse($startDate)->addDays($i)->toDateString();
        }

        $this->info("fx:push-payment-options — {$startDate} + {$days} days" . ($isDryRun ? ' [DRY RUN]' : ''));

        // ------------------------------------------------------------------
        // Step 1: Fetch rates
        // ------------------------------------------------------------------

        $this->info('Fetching exchange rates...');

        if ($this->option('usd-rate')) {
            $usdRate      = (float) $this->option('usd-rate');
            $usdSource    = 'manual';
            $usdFetchedAt = now();
        } else {
            $usdData = $this->fxService->getUsdToUzs();
            if (!$usdData) {
                $this->error('Failed to fetch USD/UZS rate from all sources. Aborting.');
                return Command::FAILURE;
            }
            $usdRate      = $usdData['rate'];
            $usdSource    = $usdData['source'];
            $usdFetchedAt = Carbon::parse($usdData['fetched_at']);
            $this->line("  USD/UZS: {$usdRate} (source: {$usdSource})");
        }

        $eurData = $this->fxService->getEurToUzs();
        if (!$eurData) {
            $this->error('Failed to fetch EUR/UZS rate. Aborting.');
            return Command::FAILURE;
        }
        $this->line("  EUR/UZS (CBU): {$eurData['rate']}");

        $rubData = $this->fxService->getRubToUzs();
        if (!$rubData) {
            $this->error('Failed to fetch RUB/UZS rate. Aborting.');
            return Command::FAILURE;
        }
        $this->line("  RUB/UZS (CBU): {$rubData['rate']}");

        // ------------------------------------------------------------------
        // Step 2: Apply margins, upsert DailyExchangeRate row for start date
        // ------------------------------------------------------------------

        $existingRow  = DailyExchangeRate::where('rate_date', $startDate)->first();
        $eurMargin    = $this->option('eur-margin') !== null ? (int) $this->option('eur-margin') : ($existingRow?->eur_margin ?? 200);
        $rubMargin    = $this->option('rub-margin') !== null ? (int) $this->option('rub-margin') : ($existingRow?->rub_margin ?? 20);
        $eurEffective = $eurData['rate'] - $eurMargin;
        $rubEffective = $rubData['rate'] - $rubMargin;

        if ($eurEffective <= 0 || $rubEffective <= 0) {
            $this->error("Effective rate would be <= 0. Check margins.");
            return Command::FAILURE;
        }

        $rateRow = DailyExchangeRate::updateOrCreate(
            ['rate_date' => $startDate],
            [
                'usd_uzs_rate'           => round($usdRate, 4),
                'eur_uzs_cbu_rate'       => round($eurData['rate'], 4),
                'eur_margin'             => $eurMargin,
                'eur_effective_rate'     => round($eurEffective, 4),
                'rub_uzs_cbu_rate'       => round($rubData['rate'], 4),
                'rub_margin'             => $rubMargin,
                'rub_effective_rate'     => round($rubEffective, 4),
                'uzs_rounding_increment' => $existingRow?->uzs_rounding_increment ?? 10000,
                'eur_rounding_increment' => $existingRow?->eur_rounding_increment ?? 1,
                'rub_rounding_increment' => $existingRow?->rub_rounding_increment ?? 100,
                'source'                 => $usdSource,
                'fetched_at'             => $usdFetchedAt,
                'set_by_user_id'         => null,
            ]
        );

        $this->info("Rate row saved: USD {$rateRow->usd_uzs_rate} | EUR eff {$rateRow->eur_effective_rate} | RUB eff {$rateRow->rub_effective_rate}");

        // ------------------------------------------------------------------
        // Step 3: Collect bookings — local DB + Beds24 API (Fix 1 + Fix 2)
        // ------------------------------------------------------------------

        $this->info("Collecting bookings for {$days} days from {$startDate}...");

        // Local DB — fast, covers synced bookings
        $localBookings = Beds24Booking::whereIn('arrival_date', $dates)
            ->whereIn('booking_status', ['confirmed', 'new'])
            ->get()
            ->keyBy('beds24_booking_id');

        $this->line("  Local DB: {$localBookings->count()} bookings");

        // Beds24 API — authoritative, catches bookings not yet synced (Fix 2)
        $apiBookings = $this->fetchFromBeds24Api($startDate, Carbon::parse($startDate)->addDays($days - 1)->toDateString());
        $this->line("  Beds24 API: " . count($apiBookings) . " bookings");

        // Merge — keep Eloquent models as-is, add raw API arrays for missing ones
        // Do NOT call ->toArray() — that would strip the Eloquent model type info
        $merged = $localBookings->all(); // ['id' => Beds24Booking, ...]
        foreach ($apiBookings as $apiBooking) {
            $id = (string) ($apiBooking['id'] ?? '');
            if ($id && !isset($merged[$id])) {
                $merged[$id] = $apiBooking; // raw API array
                $this->line("  + From API only: #{$id} ({$apiBooking['firstName']} {$apiBooking['lastName']})");
            }
        }

        $this->info("Total unique bookings to process: " . count($merged));

        // ------------------------------------------------------------------
        // Step 4: Calculate and push
        // ------------------------------------------------------------------

        $successCount = 0;
        $skipCount    = 0;
        $errorCount   = 0;

        foreach ($merged as $bookingId => $booking) {
            // Normalise — handle both Eloquent model and raw API array
            if ($booking instanceof Beds24Booking) {
                $usdAmount  = (float) ($booking->invoice_balance > 0 ? $booking->invoice_balance : $booking->total_amount);
                $guestName  = $booking->guest_name ?? 'Guest';
                $arrivalStr = $booking->arrival_date?->toDateString() ?? $startDate;
            } else {
                // Raw Beds24 API shape
                $usdAmount  = $this->resolveAmountFromApi($booking);
                $guestName  = trim(($booking['firstName'] ?? '') . ' ' . ($booking['lastName'] ?? '')) ?: 'Guest';
                $arrivalStr = $booking['arrival'] ?? $startDate;
            }

            if ($usdAmount <= 0) {
                $this->line("  Skip #{$bookingId} ({$guestName}) — no USD amount");
                $skipCount++;
                continue;
            }

            // Use the rate row for the arrival date if available, else fall back to startDate row
            $rateForBooking = DailyExchangeRate::where('rate_date', $arrivalStr)->first() ?? $rateRow;

            try {
                $options   = $this->calcService->calculate($usdAmount, $rateForBooking);
                $infoItems = $this->calcService->formatForBeds24($options, $arrivalStr);

                $this->line(sprintf(
                    '  #%s (%s) $%.2f → UZS %s | EUR %s | RUB %s',
                    $bookingId, $guestName, $usdAmount,
                    $infoItems['UZS_AMOUNT'], $infoItems['EUR_AMOUNT'], $infoItems['RUB_AMOUNT'],
                ));

                if (!$isDryRun) {
                    $this->beds24Service->writePaymentOptionsToInfoItems((int) $bookingId, $infoItems);
                }

                $successCount++;

            } catch (\Throwable $e) {
                $this->error("  ERROR #{$bookingId}: {$e->getMessage()}");
                Log::error('fx:push-payment-options — booking write failed', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
                $errorCount++;
            }
        }

        $action = $isDryRun ? 'would push' : 'pushed';
        $this->info("Done. {$action} {$successCount} | skipped {$skipCount} | errors {$errorCount}");

        Log::info('fx:push-payment-options completed', compact('startDate', 'days', 'successCount', 'skipCount', 'errorCount', 'isDryRun'));

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Beds24 API fetch (Fix 2)
    // -------------------------------------------------------------------------

    /**
     * Fetch confirmed bookings arriving between $from and $to directly from
     * the Beds24 API — catches bookings not yet in the local DB.
     */
    private function fetchFromBeds24Api(string $from, string $to): array
    {
        $all = [];

        // Beds24 API does NOT accept comma-separated status values —
        // must make one request per status.
        foreach (self::PROPERTY_IDS as $propertyId) {
            foreach (['confirmed', 'new'] as $status) {
                try {
                    $response = $this->beds24Service->apiCall('GET', '/bookings', [
                        'propertyId'     => (int) $propertyId,
                        'arrivalFrom'    => $from,
                        'arrivalTo'      => $to,
                        'status'         => $status,
                        'includeInvoice' => 'true',
                    ]);

                    $data = $response->json();
                    if (isset($data['data']) && is_array($data['data'])) {
                        $all = array_merge($all, $data['data']);
                    }
                } catch (\Throwable $e) {
                    Log::warning("fx:push-payment-options — Beds24 API fetch failed for property {$propertyId} status={$status}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $all;
    }

    /**
     * Resolve the USD amount from a raw Beds24 API booking array.
     * Prefer invoice balance (outstanding) over total if available.
     */
    private function resolveAmountFromApi(array $booking): float
    {
        // invoiceItems balance (sum of outstanding charges)
        if (!empty($booking['invoice'])) {
            $balance = collect($booking['invoice'])->sum('lineTotal');
            if ($balance > 0) return (float) $balance;
        }

        return (float) ($booking['price'] ?? $booking['totalAmount'] ?? 0);
    }
}
