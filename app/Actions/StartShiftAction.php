<?php

namespace App\Actions;

use App\Enums\ShiftStatus;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StartShiftAction
{
    /**
     * Start a new shift for a user on a specific drawer
     */
    public function execute(User $user, CashDrawer $drawer, array $data): CashierShift
    {
        $validated = Validator::make($data, [
            'beginning_saldo' => 'required|numeric|min:0',
            'currency' => 'string|size:3',
            'notes' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($user, $drawer, $validated) {
            // Check if user already has an open shift on this drawer
            $existingShift = CashierShift::where('user_id', $user->id)
                ->where('cash_drawer_id', $drawer->id)
                ->where('status', ShiftStatus::OPEN)
                ->first();

            if ($existingShift) {
                throw ValidationException::withMessages([
                    'shift' => 'You already have an open shift on this drawer.'
                ]);
            }

            // Check if drawer is active
            if (!$drawer->is_active) {
                throw ValidationException::withMessages([
                    'drawer' => 'This cash drawer is not active.'
                ]);
            }

            // Create the shift
            $shift = CashierShift::create([
                'cash_drawer_id' => $drawer->id,
                'user_id' => $user->id,
                'status' => ShiftStatus::OPEN,
                'currency' => $validated['currency'] ?? 'UZS',
                'beginning_saldo' => $validated['beginning_saldo'],
                'opened_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            return $shift;
        });
    }
}
