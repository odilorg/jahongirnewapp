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
 * Flow:
 *   1. Fetch CBU rates for USD, EUR, RUB (with fallback chain)
 *   2. Upsert daily_exchange_rates for today
 *   3. Find confirmed Beds24 bookings arriving today and tomorrow
 *   4. For each booking with a USD invoice amount, calculate UZS/EUR/RUB options
 *   5. Write results to Beds24 infoItems (template vars appear on printed form)
 *
 * Admin can re-run manually with an optional --date argument to backfill or
 * regenerate a specific day after adjusting margins:
 *   php artisan fx:push-payment-options --date=2026-03-28
 */
class CalculateAndPushDailyPaymentOptions extends Command
{
    protected $signature = 'fx:push-payment-options
                            {--date= : Target date (Y-m-d). Defaults to today.}
                            {--usd-rate= : Override the USD/UZS rate (skips API fetch for USD).}
                            {--eur-margin= : Override EUR safety margin (default: 200).}
                            {--rub-margin= : Override RUB safety margin (default: 20).}
                            {--dry-run : Calculate and log without writing to Beds24.}';

    protected $description = 'Fetch exchange rates, calculate UZS/EUR/RUB amounts, push to Beds24 infoItems';

    public function __construct(
        private readonly ExchangeRateService          $fxService,
        private readonly BookingPaymentOptionsService $calcService,
        private readonly Beds24BookingService         $beds24Service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $targetDate = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : today()->toDateString();

        $isDryRun = (bool) $this->option('dry-run');

        $this->info("fx:push-payment-options — target date: {$targetDate}" . ($isDryRun ? ' [DRY RUN]' : ''));

        // ------------------------------------------------------------------
        // Step 1: Fetch rates
        // ------------------------------------------------------------------

        $this->info('Fetching exchange rates...');

        // USD: may be overridden by --usd-rate flag
        if ($this->option('usd-rate')) {
            $usdRate      = (float) $this->option('usd-rate');
            $usdSource    = 'manual';
            $usdFetchedAt = now();
            $effectiveDate = $targetDate;
            $this->line("  USD/UZS (manual override): {$usdRate}");
        } else {
            $usdData = $this->fxService->getUsdToUzs();
            if (!$usdData) {
                $this->error('Failed to fetch USD/UZS rate from all sources. Aborting.');
                Log::error('fx:push-payment-options — USD rate fetch failed, aborting');
                return Command::FAILURE;
            }
            $usdRate       = $usdData['rate'];
            $usdSource     = $usdData['source'];
            $usdFetchedAt  = Carbon::parse($usdData['fetched_at']);
            $effectiveDate = $usdData['effective_date'];
            $this->line("  USD/UZS: {$usdRate} (source: {$usdSource})");
        }

        // EUR
        $eurData = $this->fxService->getEurToUzs();
        if (!$eurData) {
            $this->error('Failed to fetch EUR/UZS rate. Aborting.');
            Log::error('fx:push-payment-options — EUR rate fetch failed, aborting');
            return Command::FAILURE;
        }
        $eurCbu = $eurData['rate'];
        $this->line("  EUR/UZS (CBU): {$eurCbu}");

        // RUB
        $rubData = $this->fxService->getRubToUzs();
        if (!$rubData) {
            $this->error('Failed to fetch RUB/UZS rate. Aborting.');
            Log::error('fx:push-payment-options — RUB rate fetch failed, aborting');
            return Command::FAILURE;
        }
        $rubCbu = $rubData['rate'];
        $this->line("  RUB/UZS (CBU): {$rubCbu}");

        // ------------------------------------------------------------------
        // Step 2: Apply margins, upsert DailyExchangeRate row
        // ------------------------------------------------------------------

        // Existing row may have custom margins set by admin — preserve them
        // unless explicitly overridden via CLI flag.
        $existingRow = DailyExchangeRate::where('rate_date', $targetDate)->first();

        $eurMargin = $this->option('eur-margin') !== null
            ? (int) $this->option('eur-margin')
            : ($existingRow?->eur_margin ?? 200);

        $rubMargin = $this->option('rub-margin') !== null
            ? (int) $this->option('rub-margin')
            : ($existingRow?->rub_margin ?? 20);

        $eurEffective = $eurCbu - $eurMargin;
        $rubEffective = $rubCbu - $rubMargin;

        if ($eurEffective <= 0 || $rubEffective <= 0) {
            $this->error("Effective rate would be <= 0 (EUR: {$eurEffective}, RUB: {$rubEffective}). Check margins.");
            return Command::FAILURE;
        }

        $rateRow = DailyExchangeRate::updateOrCreate(
            ['rate_date' => $targetDate],
            [
                'usd_uzs_rate'          => round($usdRate, 4),
                'eur_uzs_cbu_rate'      => round($eurCbu, 4),
                'eur_margin'            => $eurMargin,
                'eur_effective_rate'    => round($eurEffective, 4),
                'rub_uzs_cbu_rate'      => round($rubCbu, 4),
                'rub_margin'            => $rubMargin,
                'rub_effective_rate'    => round($rubEffective, 4),
                'uzs_rounding_increment'=> $existingRow?->uzs_rounding_increment ?? 10000,
                'eur_rounding_increment'=> $existingRow?->eur_rounding_increment ?? 1,
                'rub_rounding_increment'=> $existingRow?->rub_rounding_increment ?? 100,
                'source'                => $usdSource,
                'fetched_at'            => $usdFetchedAt,
                'set_by_user_id'        => null, // scheduled run
            ]
        );

        $this->info("Rate row upserted for {$targetDate}:");
        $this->line("  USD/UZS: {$rateRow->usd_uzs_rate}");
        $this->line("  EUR effective: {$rateRow->eur_effective_rate} (CBU {$rateRow->eur_uzs_cbu_rate} - {$rateRow->eur_margin})");
        $this->line("  RUB effective: {$rateRow->rub_effective_rate} (CBU {$rateRow->rub_uzs_cbu_rate} - {$rateRow->rub_margin})");

        // ------------------------------------------------------------------
        // Step 3: Load relevant bookings (today + tomorrow)
        // ------------------------------------------------------------------

        $bookingDates = [
            $targetDate,
            Carbon::parse($targetDate)->addDay()->toDateString(),
        ];

        $bookings = Beds24Booking::whereIn('arrival_date', $bookingDates)
            ->whereIn('booking_status', ['confirmed', 'new'])
            ->get();

        $this->info("Found {$bookings->count()} bookings for " . implode(' & ', $bookingDates));

        if ($bookings->isEmpty()) {
            $this->info('No bookings to update. Done.');
            return Command::SUCCESS;
        }

        // ------------------------------------------------------------------
        // Step 4 & 5: Calculate options and push to Beds24
        // ------------------------------------------------------------------

        $successCount = 0;
        $skipCount    = 0;
        $errorCount   = 0;

        foreach ($bookings as $booking) {
            // Use invoice_balance if outstanding, otherwise total_amount
            $usdAmount = (float) ($booking->invoice_balance > 0
                ? $booking->invoice_balance
                : $booking->total_amount);

            if ($usdAmount <= 0) {
                $this->line("  Skip #{$booking->beds24_booking_id} — no USD amount");
                $skipCount++;
                continue;
            }

            try {
                $options   = $this->calcService->calculate($usdAmount, $rateRow);
                $infoItems = $this->calcService->formatForBeds24($options, $targetDate);

                $this->line(sprintf(
                    '  #%s (%s) $%.2f → UZS %s | EUR %s | RUB %s',
                    $booking->beds24_booking_id,
                    $booking->guest_name ?? 'Guest',
                    $usdAmount,
                    $infoItems['UZS_AMOUNT'],
                    $infoItems['EUR_AMOUNT'],
                    $infoItems['RUB_AMOUNT'],
                ));

                if (!$isDryRun) {
                    $this->beds24Service->writePaymentOptionsToInfoItems(
                        (int) $booking->beds24_booking_id,
                        $infoItems
                    );
                }

                $successCount++;

            } catch (\Throwable $e) {
                $this->error("  ERROR #{$booking->beds24_booking_id}: {$e->getMessage()}");
                Log::error('fx:push-payment-options — booking write failed', [
                    'booking_id' => $booking->beds24_booking_id,
                    'error'      => $e->getMessage(),
                ]);
                $errorCount++;
            }
        }

        $action = $isDryRun ? 'would push' : 'pushed';
        $this->info("Done. {$action} {$successCount} bookings | skipped {$skipCount} | errors {$errorCount}");

        Log::info('fx:push-payment-options completed', [
            'date'          => $targetDate,
            'success'       => $successCount,
            'skipped'       => $skipCount,
            'errors'        => $errorCount,
            'dry_run'       => $isDryRun,
            'usd_uzs_rate'  => $rateRow->usd_uzs_rate,
        ]);

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
