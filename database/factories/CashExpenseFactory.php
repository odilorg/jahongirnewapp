<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashExpense;
use App\Models\CashierShift;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal test factory for petty-cash consolidation tests.
 * Production CashExpense rows are written by CashierExpenseService — this
 * factory is for test setup only and does not mirror approval/rejection paths.
 */
class CashExpenseFactory extends Factory
{
    protected $model = CashExpense::class;

    public function definition(): array
    {
        return [
            'cashier_shift_id' => CashierShift::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'amount' => 50000.00,
            'currency' => 'UZS',
            'description' => 'Fuel for staff vehicle',
            'requires_approval' => false,
            'created_by' => User::factory(),
            'occurred_at' => now(),
            'consolidated_at' => null,
            'rejected_at' => null,
        ];
    }
}
