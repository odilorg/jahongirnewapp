<?php

namespace App\Actions;

use App\Enums\ShiftStatus;
use App\Models\CashCount;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CloseShiftAction
{
    /**
     * Close a shift with cash count and discrepancy handling
     */
    public function execute(CashierShift $shift, User $user, array $data): CashierShift
    {
        $validated = Validator::make($data, [
            'counted_end_saldo' => 'required|numeric|min:0',
            'denominations' => 'required|array|min:1',
            'denominations.*.denomination' => 'required|numeric|min:0.01',
            'denominations.*.qty' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
            'discrepancy_reason' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($shift, $user, $validated) {
            // Check if shift is open
            if (!$shift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'This shift is already closed.'
                ]);
            }

            // Calculate expected end saldo
            $expectedEndSaldo = $shift->calculateExpectedEndSaldo();
            $countedEndSaldo = $validated['counted_end_saldo'];
            $discrepancy = $countedEndSaldo - $expectedEndSaldo;

            // Validate denominations total matches counted saldo
            $denominationsTotal = 0;
            foreach ($validated['denominations'] as $denomination) {
                $denominationsTotal += $denomination['denomination'] * $denomination['qty'];
            }

            if (abs($denominationsTotal - $countedEndSaldo) > 0.01) {
                throw ValidationException::withMessages([
                    'denominations' => 'Denominations total does not match counted end saldo.'
                ]);
            }

            // Require discrepancy reason if there's a discrepancy
            if (abs($discrepancy) > 0.01 && empty($validated['discrepancy_reason'])) {
                throw ValidationException::withMessages([
                    'discrepancy_reason' => 'Discrepancy reason is required when there is a discrepancy.'
                ]);
            }

            // Update the shift
            $shift->update([
                'status' => ShiftStatus::CLOSED,
                'expected_end_saldo' => $expectedEndSaldo,
                'counted_end_saldo' => $countedEndSaldo,
                'discrepancy' => $discrepancy,
                'discrepancy_reason' => $validated['discrepancy_reason'] ?? null,
                'closed_at' => now(),
                'notes' => $validated['notes'] ?? $shift->notes,
            ]);

            // Create cash count record
            CashCount::create([
                'cashier_shift_id' => $shift->id,
                'denominations' => $validated['denominations'],
                'total' => $countedEndSaldo,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $shift->fresh();
        });
    }
}

