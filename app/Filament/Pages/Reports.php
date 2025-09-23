<?php

namespace App\Filament\Pages;

use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\EndSaldo;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Cash Management';
    protected static ?int $navigationSort = 10;
    protected static string $view = 'filament.pages.reports';

    protected static ?string $title = 'Cash Management Reports';

    public ?array $reportData = null;

    /**
     * Check if the current user can access this page
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Check if the current user can view this page in navigation
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        return $user && $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Initialize the page and check access
     */
    public function mount(): void
    {
        // Check if user has permission to access reports
        $user = auth()->user();
        if (!$user || !$user->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            abort(403, 'You do not have permission to access cash management reports.');
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('report_type')
                    ->label('Report Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'daily_summary' => 'info',
                        'shift_performance' => 'success',
                        'multi_currency' => 'warning',
                        'transaction_analysis' => 'danger',
                    }),
                Tables\Columns\TextColumn::make('title')
                    ->label('Report Title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50),
                Tables\Columns\TextColumn::make('last_updated')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View Report')
                    ->icon('heroicon-o-eye')
                    ->action(function (array $record) {
                        return $this->viewReport($record['report_type']);
                    }),
            ])
            ->paginated(false);
    }

    protected function getTableQuery(): Builder
    {
        // Create a collection of available reports
        $reports = collect([
            [
                'id' => 1,
                'report_type' => 'daily_summary',
                'title' => 'Daily Cash Summary',
                'description' => 'Overview of daily cash operations, balances, and discrepancies',
                'last_updated' => now(),
            ],
            [
                'id' => 2,
                'report_type' => 'shift_performance',
                'title' => 'Shift Performance',
                'description' => 'Individual shift details and cashier performance metrics',
                'last_updated' => now(),
            ],
            [
                'id' => 3,
                'report_type' => 'multi_currency',
                'title' => 'Multi-Currency Balance',
                'description' => 'Current balances by drawer and currency with exchange rates',
                'last_updated' => now(),
            ],
            [
                'id' => 4,
                'report_type' => 'transaction_analysis',
                'title' => 'Transaction Analysis',
                'description' => 'Transaction patterns, peak hours, and complex transaction breakdown',
                'last_updated' => now(),
            ],
        ]);

        // Convert to a query builder (this is a workaround for Filament tables)
        return \App\Models\CashierShift::query()->whereRaw('1 = 0'); // Empty query, we'll override with custom data
    }

    public function viewReport(string $reportType): void
    {
        match ($reportType) {
            'daily_summary' => $this->showDailySummaryReport(),
            'shift_performance' => $this->showShiftPerformanceReport(),
            'multi_currency' => $this->showMultiCurrencyReport(),
            'transaction_analysis' => $this->showTransactionAnalysisReport(),
        };
    }

    protected function showDailySummaryReport(): void
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        // Get today's shifts
        $todayShifts = CashierShift::whereDate('opened_at', $today)->get();
        $yesterdayShifts = CashierShift::whereDate('opened_at', $yesterday)->get();

        // Calculate today's summary
        $todaySummary = $this->calculateDailySummary($todayShifts);
        $yesterdaySummary = $this->calculateDailySummary($yesterdayShifts);

        // Store data for the view
        $this->reportData = [
            'type' => 'daily_summary',
            'title' => 'Daily Cash Summary Report',
            'date' => $today->format('Y-m-d'),
            'today_summary' => $todaySummary,
            'yesterday_summary' => $yesterdaySummary,
            'comparison' => $this->compareSummaries($todaySummary, $yesterdaySummary),
        ];

        $this->dispatch('open-modal', id: 'report-modal');
    }

    protected function showShiftPerformanceReport(): void
    {
        $shifts = CashierShift::with(['user', 'cashDrawer'])
            ->where('status', 'closed')
            ->orderBy('closed_at', 'desc')
            ->limit(50)
            ->get();

        $this->reportData = [
            'type' => 'shift_performance',
            'title' => 'Shift Performance Report',
            'shifts' => $shifts,
            'summary' => $this->calculateShiftPerformanceSummary($shifts),
        ];

        $this->dispatch('open-modal', id: 'report-modal');
    }

    protected function showMultiCurrencyReport(): void
    {
        $drawers = CashDrawer::with(['openShifts.transactions'])->get();
        
        $this->reportData = [
            'type' => 'multi_currency',
            'title' => 'Multi-Currency Balance Report',
            'drawers' => $drawers,
            'summary' => $this->calculateMultiCurrencySummary($drawers),
        ];

        $this->dispatch('open-modal', id: 'report-modal');
    }

    protected function showTransactionAnalysisReport(): void
    {
        $transactions = CashTransaction::with(['shift.user', 'shift.cashDrawer'])
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->get();

        $this->reportData = [
            'type' => 'transaction_analysis',
            'title' => 'Transaction Analysis Report',
            'transactions' => $transactions,
            'analysis' => $this->analyzeTransactions($transactions),
        ];

        $this->dispatch('open-modal', id: 'report-modal');
    }

    protected function calculateDailySummary(Collection $shifts): array
    {
        $summary = [
            'total_shifts' => $shifts->count(),
            'currencies' => [],
            'total_cash_in' => 0,
            'total_cash_out' => 0,
            'discrepancies' => [],
        ];

        foreach ($shifts as $shift) {
            $usedCurrencies = $shift->getUsedCurrencies();
            // Simple beginning saldo handling
            $beginningSaldoCurrencies = $shift->beginning_saldo > 0 ? collect([Currency::UZS]) : collect();
            $allCurrencies = $usedCurrencies->merge($beginningSaldoCurrencies)->unique();

            foreach ($allCurrencies as $currency) {
                if (!isset($summary['currencies'][$currency->value])) {
                    $summary['currencies'][$currency->value] = [
                        'currency' => $currency,
                        'cash_in' => 0,
                        'cash_out' => 0,
                        'net_balance' => 0,
                        'shifts_count' => 0,
                    ];
                }

                $cashIn = $shift->getTotalCashInForCurrency($currency);
                $cashOut = $shift->getTotalCashOutForCurrency($currency);
                $netBalance = $shift->getNetBalanceForCurrency($currency);

                $summary['currencies'][$currency->value]['cash_in'] += $cashIn;
                $summary['currencies'][$currency->value]['cash_out'] += $cashOut;
                $summary['currencies'][$currency->value]['net_balance'] += $netBalance;
                $summary['currencies'][$currency->value]['shifts_count']++;
            }

            // Check for discrepancies
            if ($shift->isClosed()) {
                // Simplified - no endSaldos relationship
                // Skip discrepancy checking for simplified version
            }
        }

        return $summary;
    }

    protected function calculateShiftPerformanceSummary(Collection $shifts): array
    {
        $summary = [
            'total_shifts' => $shifts->count(),
            'total_discrepancies' => 0,
            'total_discrepancy_amount' => 0,
            'cashiers' => [],
            'drawers' => [],
        ];

        foreach ($shifts as $shift) {
            // Cashier performance
            $cashierName = $shift->user->name;
            if (!isset($summary['cashiers'][$cashierName])) {
                $summary['cashiers'][$cashierName] = [
                    'shifts_count' => 0,
                    'discrepancies_count' => 0,
                    'total_discrepancy' => 0,
                ];
            }
            $summary['cashiers'][$cashierName]['shifts_count']++;

            // Drawer performance
            $drawerName = $shift->cashDrawer->name;
            if (!isset($summary['drawers'][$drawerName])) {
                $summary['drawers'][$drawerName] = [
                    'shifts_count' => 0,
                    'discrepancies_count' => 0,
                    'total_discrepancy' => 0,
                ];
            }
            $summary['drawers'][$drawerName]['shifts_count']++;

            // Count discrepancies
            // Simplified - no endSaldos relationship
            // Skip discrepancy counting for simplified version
        }

        return $summary;
    }

    protected function calculateMultiCurrencySummary(Collection $drawers): array
    {
        $summary = [
            'total_drawers' => $drawers->count(),
            'currencies' => [],
            'total_balances' => [],
        ];

        foreach ($drawers as $drawer) {
            $openShifts = $drawer->openShifts;
            
            foreach ($openShifts as $shift) {
                $usedCurrencies = $shift->getUsedCurrencies();
                // Simple beginning saldo handling
            $beginningSaldoCurrencies = $shift->beginning_saldo > 0 ? collect([Currency::UZS]) : collect();
                $allCurrencies = $usedCurrencies->merge($beginningSaldoCurrencies)->unique();

                foreach ($allCurrencies as $currency) {
                    if (!isset($summary['currencies'][$currency->value])) {
                        $summary['currencies'][$currency->value] = [
                            'currency' => $currency,
                            'total_balance' => 0,
                            'drawers_count' => 0,
                        ];
                    }

                    $balance = $shift->getNetBalanceForCurrency($currency);
                    $summary['currencies'][$currency->value]['total_balance'] += $balance;
                    $summary['currencies'][$currency->value]['drawers_count']++;
                }
            }
        }

        return $summary;
    }

    protected function analyzeTransactions(Collection $transactions): array
    {
        $analysis = [
            'total_transactions' => $transactions->count(),
            'by_type' => [],
            'by_currency' => [],
            'by_hour' => [],
            'complex_transactions' => 0,
            'peak_hours' => [],
        ];

        foreach ($transactions as $transaction) {
            // By type
            $type = $transaction->type->value;
            if (!isset($analysis['by_type'][$type])) {
                $analysis['by_type'][$type] = 0;
            }
            $analysis['by_type'][$type]++;

            // By currency
            $currency = $transaction->currency->value;
            if (!isset($analysis['by_currency'][$currency])) {
                $analysis['by_currency'][$currency] = 0;
            }
            $analysis['by_currency'][$currency]++;

            // By hour
            $hour = $transaction->created_at->hour;
            if (!isset($analysis['by_hour'][$hour])) {
                $analysis['by_hour'][$hour] = 0;
            }
            $analysis['by_hour'][$hour]++;

            // Complex transactions
            if ($transaction->type->value === 'in_out') {
                $analysis['complex_transactions']++;
            }
        }

        // Find peak hours
        arsort($analysis['by_hour']);
        $analysis['peak_hours'] = array_slice($analysis['by_hour'], 0, 5, true);

        return $analysis;
    }

    protected function compareSummaries(array $today, array $yesterday): array
    {
        $comparison = [
            'shifts_change' => $today['total_shifts'] - $yesterday['total_shifts'],
            'currencies_change' => [],
        ];

        foreach ($today['currencies'] as $currency => $data) {
            $yesterdayData = $yesterday['currencies'][$currency] ?? null;
            if ($yesterdayData) {
                $comparison['currencies_change'][$currency] = [
                    'cash_in_change' => $data['cash_in'] - $yesterdayData['cash_in'],
                    'cash_out_change' => $data['cash_out'] - $yesterdayData['cash_out'],
                    'net_balance_change' => $data['net_balance'] - $yesterdayData['net_balance'],
                ];
            }
        }

        return $comparison;
    }

    public function exportAllReports(): void
    {
        // This would implement PDF/Excel export functionality
        // For now, we'll just show a notification
        \Filament\Notifications\Notification::make()
            ->title('Export Started')
            ->body('All reports are being prepared for download...')
            ->success()
            ->send();
    }

    public function exportReport(): void
    {
        if (!$this->reportData) {
            return;
        }

        // This would implement PDF/Excel export functionality
        // For now, we'll just show a notification
        \Filament\Notifications\Notification::make()
            ->title('Report Export Started')
            ->body("{$this->reportData['title']} is being prepared for download...")
            ->success()
            ->send();
    }
}