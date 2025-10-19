<?php

namespace App\Services;

use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\CashDrawer;
use App\Enums\ShiftStatus;
use App\Enums\TransactionType;
use App\Enums\Currency;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

class TelegramReportService
{
    /**
     * Get today's summary report for manager
     */
    public function getTodaySummary(User $manager): array
    {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        $today = Carbon::today();
        $shiftsQuery = $this->getScopedShiftsQuery($manager)
            ->whereDate('opened_at', $today);

        // Get shift counts by status
        $totalShifts = $shiftsQuery->count();
        $openShifts = (clone $shiftsQuery)->where('status', ShiftStatus::OPEN)->count();
        $closedShifts = (clone $shiftsQuery)->where('status', ShiftStatus::CLOSED)->count();
        $underReview = 0; // Status removed from enum

        // Get transaction statistics
        $shiftIds = $shiftsQuery->pluck('id');
        $transactions = CashTransaction::whereIn('cashier_shift_id', $shiftIds)
            ->whereDate('occurred_at', $today)
            ->get();

        $totalTransactions = $transactions->count();
        $cashInCount = $transactions->where('type', TransactionType::IN)->count();
        $cashOutCount = $transactions->where('type', TransactionType::OUT)->count();
        $exchangeCount = $transactions->where('type', TransactionType::IN_OUT)->count();

        // Calculate totals by currency
        $currencyTotals = $this->calculateCurrencyTotals($transactions);

        // Get active cashiers (users with currently open shifts, regardless of when opened)
        $activeCashiers = $this->getScopedShiftsQuery($manager)
            ->where('status', ShiftStatus::OPEN)
            ->with('user')
            ->get()
            ->map(fn($s) => $s->user?->name)
            ->filter()
            ->unique()
            ->count();

        // Count discrepancies (check for shifts with discrepancy fields set)
        $discrepancyCount = 0; // Feature not implemented yet

        // Get top performer
        $topPerformer = $this->getTopPerformer($shiftsQuery, $today);

        return [
            'date' => $today,
            'location' => $this->getManagerLocations($manager),
            'shifts' => [
                'total' => $totalShifts,
                'open' => $openShifts,
                'closed' => $closedShifts,
                'under_review' => $underReview,
            ],
            'transactions' => [
                'total' => $totalTransactions,
                'cash_in' => $cashInCount,
                'cash_out' => $cashOutCount,
                'exchange' => $exchangeCount,
            ],
            'currency_totals' => $currencyTotals,
            'active_cashiers' => $activeCashiers,
            'discrepancies' => $discrepancyCount,
            'top_performer' => $topPerformer,
        ];
    }

    /**
     * Get shift performance report
     */
    public function getShiftPerformance(User $manager, Carbon $date): array
    {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        $shifts = $this->getScopedShiftsQuery($manager)
            ->whereDate('opened_at', $date)
            ->with(['user', 'cashDrawer', 'transactions'])
            ->orderBy('opened_at', 'desc')
            ->get();

        $performance = $shifts->map(function ($shift) {
            $duration = $shift->opened_at->diffInMinutes($shift->closed_at ?? now());
            $transactionCount = $shift->transactions->count();
            $currencyBalances = $this->getShiftCurrencyBalances($shift);

            return [
                'shift_id' => $shift->id,
                'cashier_name' => $shift->user->name,
                'drawer_name' => $shift->cashDrawer->name,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'duration_minutes' => $duration,
                'status' => $shift->status,
                'transaction_count' => $transactionCount,
                'currency_balances' => $currencyBalances,
                'has_discrepancy' => false, // Feature not implemented
                'discrepancy_info' => null,
            ];
        });

        return [
            'date' => $date,
            'shifts' => $performance,
            'total_shifts' => $shifts->count(),
            'total_transactions' => $shifts->sum(fn($s) => $s->transactions->count()),
            'avg_duration' => $shifts->avg(fn($s) => $s->opened_at->diffInMinutes($s->closed_at ?? now())),
        ];
    }

    /**
     * Get individual shift detail
     */
    public function getShiftDetail(int $shiftId, User $manager): array
    {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        $shift = CashierShift::with([
            'user',
            'cashDrawer',
            'transactions',
            'beginningSaldos',
            'endSaldos',
            'approver',
            'rejecter'
        ])->find($shiftId);

        if (!$shift) {
            return ['error' => 'Shift not found'];
        }

        // Check if manager can access this shift
        if (!$this->canAccessShift($manager, $shift)) {
            return ['error' => 'Unauthorized to view this shift'];
        }

        // Group transactions by type
        $transactionsByType = $shift->transactions->groupBy('type');

        // Get currency-wise breakdown
        $currencyBreakdown = $this->getShiftCurrencyBalances($shift);

        // Get recent transactions (last 20)
        $recentTransactions = $shift->transactions()
            ->orderBy('occurred_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($txn) {
                return [
                    'id' => $txn->id,
                    'type' => $txn->type,
                    'amount' => $txn->amount,
                    'currency' => $txn->currency,
                    'category' => $txn->category,
                    'notes' => $txn->notes,
                    'occurred_at' => $txn->occurred_at,
                ];
            });

        return [
            'shift' => [
                'id' => $shift->id,
                'cashier' => $shift->user->name,
                'drawer' => $shift->cashDrawer->name,
                'location' => $shift->cashDrawer->location ?? 'N/A',
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'duration' => $shift->opened_at->diffInMinutes($shift->closed_at ?? now()),
                'status' => $shift->status,
            ],
            'transactions' => [
                'total' => $shift->transactions->count(),
                'by_type' => [
                    'cash_in' => $transactionsByType->get(TransactionType::IN)?->count() ?? 0,
                    'cash_out' => $transactionsByType->get(TransactionType::OUT)?->count() ?? 0,
                    'exchange' => $transactionsByType->get(TransactionType::IN_OUT)?->count() ?? 0,
                ],
                'recent' => $recentTransactions,
            ],
            'balances' => $currencyBreakdown,
            'discrepancy' => null, // Feature not implemented yet
            'approval' => [
                'approved_by' => $shift->approver?->name,
                'approved_at' => $shift->approved_at,
                'rejected_by' => $shift->rejecter?->name,
                'rejected_at' => $shift->rejected_at,
                'rejection_reason' => $shift->rejection_reason,
            ],
        ];
    }

    /**
     * Get transaction activity report
     */
    public function getTransactionActivity(User $manager, Carbon $startDate, Carbon $endDate): array
    {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        $shiftIds = $this->getScopedShiftsQuery($manager)
            ->whereBetween('opened_at', [$startDate, $endDate])
            ->pluck('id');

        $transactions = CashTransaction::whereIn('cashier_shift_id', $shiftIds)
            ->whereBetween('occurred_at', [$startDate, $endDate])
            ->with(['shift.user', 'shift.cashDrawer'])
            ->get();

        // Group by category
        $byCategory = $transactions->groupBy('category')
            ->map(fn($group) => [
                'count' => $group->count(),
                'total_amount' => $group->sum('amount'),
            ]);

        // Group by currency
        $byCurrency = $transactions->groupBy('currency')
            ->map(fn($group) => [
                'count' => $group->count(),
                'cash_in' => $group->where('type', TransactionType::IN)->sum('amount'),
                'cash_out' => $group->where('type', TransactionType::OUT)->sum('amount'),
                'net' => $group->sum('effective_amount'),
            ]);

        // Top cashiers by transaction volume
        $topCashiers = $transactions->groupBy('shift.user.name')
            ->map(fn($group) => $group->count())
            ->sortDesc()
            ->take(5);

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => [
                'total_transactions' => $transactions->count(),
                'total_amount' => $transactions->sum('amount'),
            ],
            'by_category' => $byCategory,
            'by_currency' => $byCurrency,
            'top_cashiers' => $topCashiers,
            'by_type' => [
                'cash_in' => $transactions->where('type', TransactionType::IN)->count(),
                'cash_out' => $transactions->where('type', TransactionType::OUT)->count(),
                'exchange' => $transactions->where('type', TransactionType::IN_OUT)->count(),
            ],
        ];
    }

    /**
     * Get multi-location summary for managers with multiple locations
     */
    public function getMultiLocationSummary(User $manager): array
    {
        if (!$this->isManagerAuthorized($manager)) {
            return ['error' => 'Unauthorized'];
        }

        $today = Carbon::today();

        // Get all distinct locations from cash drawers (location is a string field)
        $locations = CashDrawer::query()
            ->whereNotNull('location')
            ->distinct()
            ->pluck('location');

        $locationData = [];

        foreach ($locations as $locationName) {
            $drawerIds = CashDrawer::where('location', $locationName)->pluck('id');

            $shifts = CashierShift::whereIn('cash_drawer_id', $drawerIds)
                ->whereDate('opened_at', $today)
                ->get();

            $transactions = CashTransaction::whereIn('cashier_shift_id', $shifts->pluck('id'))
                ->whereDate('occurred_at', $today)
                ->get();

            $locationData[] = [
                'location_name' => $locationName,
                'shifts' => [
                    'total' => $shifts->count(),
                    'open' => $shifts->where('status', ShiftStatus::OPEN)->count(),
                    'closed' => $shifts->where('status', ShiftStatus::CLOSED)->count(),
                ],
                'transactions' => [
                    'total' => $transactions->count(),
                    'total_amount' => $transactions->sum('amount'),
                ],
                'active_cashiers' => $shifts->where('status', ShiftStatus::OPEN)->unique('user_id')->count(),
            ];
        }

        return [
            'date' => $today,
            'locations' => $locationData,
            'total_locations' => count($locationData),
        ];
    }

    /**
     * Check if user is authorized as manager
     */
    protected function isManagerAuthorized(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'super_admin']);
    }

    /**
     * Check if manager can access specific shift
     */
    protected function canAccessShift(User $manager, CashierShift $shift): bool
    {
        // For now, all managers can access all shifts since location-based
        // access control is not implemented (location is just a string field)
        return true;
    }

    /**
     * Get scoped shifts query based on manager's locations
     */
    protected function getScopedShiftsQuery(User $manager): Builder
    {
        // For now, all managers can see all shifts since user-to-location
        // relationships don't exist (location is just a string field)
        return CashierShift::query();
    }

    /**
     * Calculate currency totals from transactions
     */
    protected function calculateCurrencyTotals(Collection $transactions): array
    {
        $totals = [];

        foreach (Currency::cases() as $currency) {
            $currencyTransactions = $transactions->where('currency', $currency->value);

            if ($currencyTransactions->isEmpty()) {
                continue;
            }

            $cashIn = $currencyTransactions->where('type', TransactionType::IN)->sum('amount');
            $cashOut = $currencyTransactions->where('type', TransactionType::OUT)->sum('amount');
            $net = $cashIn - $cashOut;

            $totals[$currency->value] = [
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'net' => $net,
            ];
        }

        return $totals;
    }

    /**
     * Get shift currency balances
     */
    protected function getShiftCurrencyBalances(CashierShift $shift): array
    {
        $balances = [];

        foreach (Currency::cases() as $currency) {
            $net = $shift->getNetBalanceForCurrency($currency);

            if ($net != 0) {
                $balances[$currency->value] = $net;
            }
        }

        return $balances;
    }

    /**
     * Get discrepancy information for a shift
     */
    protected function getDiscrepancyInfo(CashierShift $shift): array
    {
        $discrepancies = [];

        if ($shift->endSaldos) {
            foreach ($shift->endSaldos as $endSaldo) {
                if ($endSaldo->discrepancy != 0) {
                    $discrepancies[] = [
                        'currency' => $endSaldo->currency->value,
                        'expected' => $endSaldo->expected_end_saldo,
                        'counted' => $endSaldo->counted_end_saldo,
                        'discrepancy' => $endSaldo->discrepancy,
                    ];
                }
            }
        }

        return [
            'discrepancies' => $discrepancies,
            'reason' => $shift->discrepancy_reason,
        ];
    }

    /**
     * Get top performer for the day
     */
    protected function getTopPerformer(Builder $shiftsQuery, Carbon $date): ?array
    {
        $shifts = $shiftsQuery->with('user', 'transactions')
            ->whereDate('opened_at', $date)
            ->get();

        if ($shifts->isEmpty()) {
            return null;
        }

        $topShift = $shifts->sortByDesc(fn($s) => $s->transactions->count())->first();

        return [
            'name' => $topShift->user->name,
            'transaction_count' => $topShift->transactions->count(),
            'shift_id' => $topShift->id,
        ];
    }

    /**
     * Get manager's location names
     */
    protected function getManagerLocations(User $manager): string
    {
        // Return all locations since user-to-location relationships don't exist
        return 'All Locations';
    }
}
