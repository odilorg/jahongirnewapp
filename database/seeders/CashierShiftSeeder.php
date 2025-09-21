<?php

namespace Database\Seeders;

use App\Actions\CloseShiftAction;
use App\Actions\RecordTransactionAction;
use App\Actions\StartShiftAction;
use App\Enums\ShiftStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\CashDrawer;
use App\Models\CashCount;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CashierShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Only run in local environment
        if (app()->environment('production')) {
            return;
        }

        $drawers = CashDrawer::all();
        $users = User::take(3)->get();

        if ($drawers->isEmpty() || $users->isEmpty()) {
            return;
        }

        // Create some demo shifts
        foreach ($drawers->take(2) as $drawer) {
            foreach ($users->take(2) as $user) {
                // Create a closed shift
                $shift = CashierShift::create([
                    'cash_drawer_id' => $drawer->id,
                    'user_id' => $user->id,
                    'status' => ShiftStatus::CLOSED,
                    'currency' => 'UZS',
                    'beginning_saldo' => 100000,
                    'opened_at' => now()->subHours(8),
                    'closed_at' => now()->subHours(1),
                ]);

                // Add some transactions
                $transactions = [
                    [
                        'type' => TransactionType::IN,
                        'amount' => 50000,
                        'category' => TransactionCategory::SALE,
                        'reference' => 'INV-001',
                        'notes' => 'Room payment',
                        'created_by' => $user->id,
                        'occurred_at' => now()->subHours(7),
                    ],
                    [
                        'type' => TransactionType::IN,
                        'amount' => 25000,
                        'category' => TransactionCategory::SALE,
                        'reference' => 'INV-002',
                        'notes' => 'Restaurant payment',
                        'created_by' => $user->id,
                        'occurred_at' => now()->subHours(6),
                    ],
                    [
                        'type' => TransactionType::OUT,
                        'amount' => 15000,
                        'category' => TransactionCategory::EXPENSE,
                        'reference' => 'EXP-001',
                        'notes' => 'Office supplies',
                        'created_by' => $user->id,
                        'occurred_at' => now()->subHours(5),
                    ],
                    [
                        'type' => TransactionType::OUT,
                        'amount' => 10000,
                        'category' => TransactionCategory::CHANGE,
                        'reference' => null,
                        'notes' => 'Change given to guest',
                        'created_by' => $user->id,
                        'occurred_at' => now()->subHours(4),
                    ],
                ];

                foreach ($transactions as $transactionData) {
                    CashTransaction::create([
                        'cashier_shift_id' => $shift->id,
                        ...$transactionData,
                    ]);
                }

                // Update shift with calculated values
                $shift->update([
                    'expected_end_saldo' => $shift->calculateExpectedEndSaldo(),
                    'counted_end_saldo' => 150000, // Slight discrepancy
                    'discrepancy' => 1000,
                    'discrepancy_reason' => 'Minor counting error',
                ]);

                // Create cash count
                CashCount::create([
                    'cashier_shift_id' => $shift->id,
                    'denominations' => [
                        ['denomination' => 1000, 'qty' => 20],
                        ['denomination' => 5000, 'qty' => 10],
                        ['denomination' => 10000, 'qty' => 8],
                        ['denomination' => 20000, 'qty' => 3],
                        ['denomination' => 50000, 'qty' => 1],
                        ['denomination' => 100000, 'qty' => 1],
                    ],
                    'total' => 150000,
                    'notes' => 'Cash count completed',
                ]);
            }
        }

        // Create one open shift for demo
        $openShift = CashierShift::create([
            'cash_drawer_id' => $drawers->first()->id,
            'user_id' => $users->first()->id,
            'status' => ShiftStatus::OPEN,
            'currency' => 'UZS',
            'beginning_saldo' => 50000,
            'opened_at' => now()->subHours(2),
        ]);

        // Add some transactions to the open shift
        $openTransactions = [
            [
                'type' => TransactionType::IN,
                'amount' => 30000,
                'category' => TransactionCategory::SALE,
                'reference' => 'INV-003',
                'notes' => 'Room service payment',
                'created_by' => $users->first()->id,
                'occurred_at' => now()->subHour(),
            ],
            [
                'type' => TransactionType::OUT,
                'amount' => 5000,
                'category' => TransactionCategory::CHANGE,
                'reference' => null,
                'notes' => 'Change given',
                'created_by' => $users->first()->id,
                'occurred_at' => now()->subMinutes(30),
            ],
        ];

        foreach ($openTransactions as $transactionData) {
            CashTransaction::create([
                'cashier_shift_id' => $openShift->id,
                ...$transactionData,
            ]);
        }

        $openShift->update([
            'expected_end_saldo' => $openShift->calculateExpectedEndSaldo(),
        ]);
    }
}