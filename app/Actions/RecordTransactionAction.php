<?php

namespace App\Actions;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordTransactionAction
{
    /**
     * Record a cash transaction for a shift
     */
    public function execute(CashierShift $shift, User $user, array $data): CashTransaction
    {
        $validated = Validator::make($data, [
            'type' => 'required|in:in,out,in_out',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:UZS,USD,EUR,RUB',
            'out_currency' => 'nullable|string|in:UZS,USD,EUR,RUB',
            'out_amount' => 'nullable|numeric|min:0.01',
            'category' => 'nullable|in:sale,refund,expense,deposit,change,other',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'occurred_at' => 'nullable|date',
        ])->validate();

        if ($validated['type'] === 'in_out') {
            if (empty($validated['out_currency']) || empty($validated['out_amount'])) {
                throw ValidationException::withMessages([
                    'out_currency' => 'Out currency is required for complex transactions.',
                    'out_amount' => 'Out amount is required for complex transactions.',
                ]);
            }
        }

        return DB::transaction(function () use ($shift, $user, $validated) {
            // Check if shift is open
            if (!$shift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Cannot record transactions on a closed shift.'
                ]);
            }

            // Create the transaction
            $transaction = CashTransaction::create([
                'cashier_shift_id' => $shift->id,
                'type' => TransactionType::from($validated['type']),
                'amount' => $validated['amount'],
                'currency' => $validated['currency'],
                'category' => $validated['category'] ? TransactionCategory::from($validated['category']) : null,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['type'] === 'in_out'
                    ? trim(($validated['notes'] ?? '') . ' (Part 1 of complex transaction)')
                    : ($validated['notes'] ?? null),
                'created_by' => $user->id,
                'occurred_at' => $validated['occurred_at'] ?? now(),
            ]);

            if ($transaction->type === TransactionType::IN_OUT && !empty($validated['out_currency']) && !empty($validated['out_amount'])) {
                CashTransaction::create([
                    'cashier_shift_id' => $shift->id,
                    'type' => TransactionType::OUT,
                    'amount' => $validated['out_amount'],
                    'currency' => $validated['out_currency'],
                    'category' => $validated['category']
                        ? TransactionCategory::from($validated['category'])
                        : TransactionCategory::CHANGE,
                    'reference' => $validated['reference'] ?? null,
                    'notes' => trim(($validated['notes'] ?? '') . ' (Part 2 of complex transaction)'),
                    'created_by' => $user->id,
                    'occurred_at' => $validated['occurred_at'] ?? now(),
                ]);
            }

            // Update shift's expected end saldo
            $shift->update([
                'expected_end_saldo' => $shift->calculateExpectedEndSaldo()
            ]);

            return $transaction;
        });
    }
}


