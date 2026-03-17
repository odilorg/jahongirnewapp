<?php

namespace App\Services;

use App\Models\CashExpense;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;

class CashierExpenseService
{
    /**
     * Record an expense against an open shift.
     *
     * Creates both a CashExpense and a linked CashTransaction (type=out)
     * inside DB::transaction with lockForUpdate + revalidation.
     *
     * @param  int    $shiftId      Shift to record expense against
     * @param  array  $expenseData  Keys: cat_id, cat_name, amount, currency, desc, needs_approval
     * @param  int    $createdBy    User ID of the cashier
     * @param  string $callbackId   Telegram callback ID for idempotency (empty = skip)
     * @return CashExpense          The created expense record
     *
     * @throws \RuntimeException If shift is closed or not found under lock
     * @throws \Exception        On any DB failure (transaction auto-rolls back)
     */
    public function recordExpense(int $shiftId, array $expenseData, int $createdBy, string $callbackId = ''): CashExpense
    {
        $expense = null;

        DB::transaction(function () use ($shiftId, $expenseData, $createdBy, $callbackId, &$expense) {
            // Lock shift row and revalidate — another request may have closed it
            $lockedShift = CashierShift::where('id', $shiftId)->lockForUpdate()->first();
            if (!$lockedShift || !$lockedShift->isOpen()) {
                throw new \RuntimeException('Shift closed during confirmation');
            }

            $expense = CashExpense::create([
                'cashier_shift_id'    => $lockedShift->id,
                'expense_category_id' => $expenseData['cat_id'],
                'amount'              => $expenseData['amount'],
                'currency'            => $expenseData['currency'],
                'description'         => $expenseData['desc'],
                'requires_approval'   => $expenseData['needs_approval'] ?? false,
                'created_by'          => $createdBy,
                'occurred_at'         => now(),
            ]);

            CashTransaction::create([
                'cashier_shift_id' => $lockedShift->id,
                'type'             => 'out',
                'amount'           => $expenseData['amount'],
                'currency'         => $expenseData['currency'],
                'category'         => 'expense',
                'reference'        => "Расход: {$expenseData['cat_name']}",
                'notes'            => $expenseData['desc'],
                'created_by'       => $createdBy,
                'occurred_at'      => now(),
            ]);

            if ($callbackId) {
                $this->succeedCallback($callbackId);
            }
        });

        return $expense;
    }

    private function succeedCallback(string $callbackId): void
    {
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);
    }
}
