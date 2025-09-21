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
            'notes' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($user, $drawer, $validated) {
            // Check if user already has ANY open shift (across all drawers)
            $existingShift = CashierShift::getUserOpenShift($user->id);

            if ($existingShift) {
                throw ValidationException::withMessages([
                    'shift' => "You already have an open shift on drawer '{$existingShift->cashDrawer->name}'. Please close it before starting a new shift."
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
                'beginning_saldo' => $validated['beginning_saldo'],
                'opened_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            return $shift;
        });
    }
}

