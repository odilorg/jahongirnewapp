<?php

namespace App\Actions;

use App\Enums\ShiftStatus;
use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\BeginningSaldo;
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
            'beginning_saldo' => 'nullable|numeric|min:0',
            'beginning_saldo_uzs' => 'nullable|numeric|min:0',
            'beginning_saldo_usd' => 'nullable|numeric|min:0',
            'beginning_saldo_eur' => 'nullable|numeric|min:0',
            'beginning_saldo_rub' => 'nullable|numeric|min:0',
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
                'beginning_saldo' => $validated['beginning_saldo'] ?? $validated['beginning_saldo_uzs'] ?? 0,
                'opened_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Create beginning saldos for each currency
            $currencies = [
                'uzs' => Currency::UZS,
                'usd' => Currency::USD,
                'eur' => Currency::EUR,
                'rub' => Currency::RUB,
            ];

            foreach ($currencies as $key => $currency) {
                $amount = $validated["beginning_saldo_{$key}"] ?? 0;
                if ($amount > 0) {
                    BeginningSaldo::create([
                        'cashier_shift_id' => $shift->id,
                        'currency' => $currency,
                        'amount' => $amount,
                    ]);
                }
            }

            return $shift;
        });
    }
}

