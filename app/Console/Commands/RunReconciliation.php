<?php

namespace App\Console\Commands;

use App\Services\OwnerAlertService;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunReconciliation extends Command
{
    protected $signature   = 'cash:reconcile {--date= : Date for departure check (Y-m-d)} {--period= : Full period reconciliation (7d, 30d, etc.)}';
    protected $description = 'Reconcile Beds24 payments vs CashTransaction records and alert owner on discrepancies';

    public function __construct(
        protected ReconciliationService $reconciliation,
        protected OwnerAlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Tashkent')
            : now('Asia/Tashkent');

        // Mode 1: Period reconciliation (--period=7d or --period=30d)
        if ($period = $this->option('period')) {
            $days = (int) str_replace('d', '', $period);
            $from = $date->copy()->subDays($days);
            $to = $date->copy();

            $this->info("Running period reconciliation: {$from->format('Y-m-d')} to {$to->format('Y-m-d')}...");
            $results = $this->reconciliation->reconcile($from, $to);
        } else {
            // Mode 2: Daily departure check (default)
            $this->info("Running departure reconciliation for {$date->format('Y-m-d')}...");
            $results = $this->reconciliation->reconcileDepartures($date);
        }

        $this->displayResults($results);

        // Send Telegram alert if there are flagged items
        if (!empty($results['flagged'])) {
            $this->alertService->sendReconciliationAlert($results, $date->format('d.m.Y'));
            $this->warn('Discrepancies found! Alert sent to owner.');
        } else {
            $this->info('No discrepancies found.');
            // Still send a clean report daily
            $this->alertService->sendReconciliationSummary($results, $date->format('d.m.Y'));
        }

        return self::SUCCESS;
    }

    private function displayResults(array $results): void
    {
        $this->info("Results: Total={$results['total']}, Matched={$results['matched']}, Underpaid={$results['underpaid']}, Overpaid={$results['overpaid']}, No Payment={$results['no_payment']}");

        foreach ($results['flagged'] as $flag) {
            $this->warn("  ⚠ Booking #{$flag['booking_id']}: Expected {$flag['expected']} {$flag['currency']}, Got {$flag['reported']} (Δ {$flag['discrepancy']})");
        }
    }
}
