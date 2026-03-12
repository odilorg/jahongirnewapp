<?php

namespace App\Console\Commands;

use App\Enums\TransactionType;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\OwnerAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyCashReport extends Command
{
    protected $signature   = 'cash:daily-report {--date= : Date in Y-m-d format, defaults to today}';
    protected $description = 'Send daily cash flow report to owner via Telegram';

    public function __construct(protected OwnerAlertService $alertService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return $this->generateReport();
        } catch (\Throwable $e) {
            Log::error('Daily cash report failed', ['error' => $e->getMessage()]);
            $this->error("Report failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function generateReport(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Asia/Tashkent')
            : now('Asia/Tashkent');

        $startOfDay = $date->copy()->startOfDay();
        $endOfDay   = $date->copy()->endOfDay();

        $this->info("Generating cash report for {$date->format('Y-m-d')}...");

        // Get all transactions for the day
        $transactions = CashTransaction::whereBetween('occurred_at', [$startOfDay, $endOfDay])->get();

        if ($transactions->isEmpty()) {
            $this->alertService->sendDailyCashReport([
                'date'       => $date->format('d.m.Y'),
                'income'     => [],
                'expenses'   => [],
                'balance'    => [],
                'shift_info' => null,
            ]);
            $this->info('No transactions — sent empty report.');
            return self::SUCCESS;
        }

        // Income by currency and payment method
        $income = [];
        $transactions->where('type', TransactionType::IN)->each(function ($tx) use (&$income) {
            $currency = $this->getCurrency($tx);
            if (!isset($income[$currency])) {
                $income[$currency] = ['total' => 0, 'by_method' => []];
            }
            $income[$currency]['total'] += $tx->amount;

            $method = $tx->payment_method ?: 'unknown';
            $income[$currency]['by_method'][$method] = ($income[$currency]['by_method'][$method] ?? 0) + $tx->amount;
        });

        // Expenses by currency with item details
        $expenses = [];
        $transactions->where('type', TransactionType::OUT)->each(function ($tx) use (&$expenses) {
            $currency = $this->getCurrency($tx);
            if (!isset($expenses[$currency])) {
                $expenses[$currency] = ['total' => 0, 'items' => []];
            }
            $expenses[$currency]['total'] += $tx->amount;
            $expenses[$currency]['items'][] = [
                'notes'  => $tx->notes ?: 'Без описания',
                'amount' => $tx->amount,
            ];
        });

        // Balance per currency
        $balance = [];
        foreach ($transactions as $tx) {
            $currency = $this->getCurrency($tx);
            if (!isset($balance[$currency])) {
                $balance[$currency] = ['in' => 0, 'out' => 0, 'net' => 0];
            }
            if ($tx->type === TransactionType::IN) {
                $balance[$currency]['in'] += $tx->amount;
            } elseif ($tx->type === TransactionType::OUT) {
                $balance[$currency]['out'] += $tx->amount;
            }
            $balance[$currency]['net'] = $balance[$currency]['in'] - $balance[$currency]['out'];
        }

        // Shift info
        $shifts = CashierShift::whereDate('opened_at', $date->toDateString())
            ->orWhere(function ($q) use ($startOfDay, $endOfDay) {
                $q->where('status', 'open')
                  ->where('opened_at', '<=', $endOfDay);
            })
            ->with('user')
            ->get();

        $shiftInfo = $shifts->map(function ($s) {
            $name   = $s->user?->name ?? 'Неизвестно';
            $status = $s->status->value === 'open' ? '🟢 открыта' : '🔴 закрыта';
            return "{$name} ({$status})";
        })->implode(', ');

        $data = [
            'date'       => $date->format('d.m.Y'),
            'income'     => $income,
            'expenses'   => $expenses,
            'balance'    => $balance,
            'shift_info' => $shiftInfo ?: null,
        ];

        $this->alertService->sendDailyCashReport($data);

        $this->info('Daily cash report sent successfully.');
        return self::SUCCESS;
    }

    private function getCurrency(CashTransaction $tx): string
    {
        return is_string($tx->currency) ? $tx->currency : ($tx->currency->value ?? 'UZS');
    }
}
