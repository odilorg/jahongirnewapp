<?php

namespace App\Console\Commands;

use App\Enums\TransactionType;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\OwnerAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMonthlyCashReport extends Command
{
    protected $signature   = 'cash:monthly-report {--month= : Month in Y-m format, defaults to last month}';
    protected $description = 'Send monthly cash flow summary to owner via Telegram';

    public function __construct(protected OwnerAlertService $alertService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('month')) {
            $month = Carbon::parse($this->option('month') . '-01', 'Asia/Tashkent');
        } else {
            $month = now('Asia/Tashkent')->subMonth()->startOfMonth();
        }

        $startOfMonth = $month->copy()->startOfMonth();
        $endOfMonth   = $month->copy()->endOfMonth()->endOfDay();

        // Previous month for comparison
        $prevStart = $month->copy()->subMonth()->startOfMonth();
        $prevEnd   = $month->copy()->subMonth()->endOfMonth()->endOfDay();

        $this->info("Generating monthly cash report for {$month->format('F Y')}...");

        $transactions = CashTransaction::whereBetween('occurred_at', [$startOfMonth, $endOfMonth])->get();

        if ($transactions->isEmpty()) {
            $this->info('No transactions found for this month. Skipping report.');
            return self::SUCCESS;
        }

        // Income by currency and method
        $income = [];
        $transactions->where('type', TransactionType::IN)->each(function ($tx) use (&$income) {
            $c = $this->getCurrency($tx);
            if (!isset($income[$c])) $income[$c] = ['total' => 0, 'by_method' => []];
            $income[$c]['total'] += $tx->amount;
            $m = $tx->payment_method ?: 'unknown';
            $income[$c]['by_method'][$m] = ($income[$c]['by_method'][$m] ?? 0) + $tx->amount;
        });

        // Expenses grouped by notes (category)
        $expenses = [];
        $expensesByCategory = [];
        $transactions->where('type', TransactionType::OUT)->each(function ($tx) use (&$expenses, &$expensesByCategory) {
            $c = $this->getCurrency($tx);
            if (!isset($expenses[$c])) $expenses[$c] = ['total' => 0, 'items' => []];
            $expenses[$c]['total'] += $tx->amount;

            // Group expenses by notes for monthly summary
            $key = strtolower(trim($tx->notes ?: 'Без описания'));
            $expensesByCategory[$c][$key] = ($expensesByCategory[$c][$key] ?? 0) + $tx->amount;
        });

        // Convert grouped expenses to items (top categories, not individual entries)
        foreach ($expensesByCategory as $currency => $categories) {
            arsort($categories);
            $expenses[$currency]['items'] = [];
            foreach ($categories as $name => $amount) {
                $expenses[$currency]['items'][] = ['notes' => ucfirst($name), 'amount' => $amount];
            }
        }

        // Balance
        $balance = [];
        foreach ($transactions as $tx) {
            $c = $this->getCurrency($tx);
            if (!isset($balance[$c])) $balance[$c] = ['in' => 0, 'out' => 0, 'net' => 0];
            if ($tx->type === TransactionType::IN) {
                $balance[$c]['in'] += $tx->amount;
            } elseif ($tx->type === TransactionType::OUT) {
                $balance[$c]['out'] += $tx->amount;
            }
            $balance[$c]['net'] = $balance[$c]['in'] - $balance[$c]['out'];
        }

        // Shifts count
        $shiftsCount = CashierShift::whereBetween('opened_at', [$startOfMonth, $endOfMonth])->count();

        // Previous month comparison
        $prevTransactions = CashTransaction::whereBetween('occurred_at', [$prevStart, $prevEnd])->get();
        $comparison = null;

        if ($prevTransactions->isNotEmpty()) {
            $prevIn  = $prevTransactions->where('type', TransactionType::IN)->sum('amount');
            $prevOut = $prevTransactions->where('type', TransactionType::OUT)->sum('amount');
            $currIn  = $transactions->where('type', TransactionType::IN)->sum('amount');
            $currOut = $transactions->where('type', TransactionType::OUT)->sum('amount');

            $inDiff  = $currIn - $prevIn;
            $outDiff = $currOut - $prevOut;
            $inSign  = $inDiff >= 0 ? '+' : '';
            $outSign = $outDiff >= 0 ? '+' : '';

            $comparison = implode("\n", [
                "  Доход: {$inSign}" . number_format($inDiff, 2) . ($prevIn > 0 ? ' (' . round(($inDiff / $prevIn) * 100) . '%)' : ''),
                "  Расход: {$outSign}" . number_format($outDiff, 2) . ($prevOut > 0 ? ' (' . round(($outDiff / $prevOut) * 100) . '%)' : ''),
            ]);
        }

        $data = [
            'period'                => $month->translatedFormat('F Y'),
            'income'                => $income,
            'expenses'              => $expenses,
            'balance'               => $balance,
            'shifts_count'          => $shiftsCount,
            'prev_month_comparison' => $comparison,
        ];

        $this->alertService->sendMonthlyCashReport($data);

        $this->info('Monthly cash report sent successfully.');
        return self::SUCCESS;
    }

    private function getCurrency(CashTransaction $tx): string
    {
        return is_string($tx->currency) ? $tx->currency : ($tx->currency->value ?? 'UZS');
    }
}
