<?php

namespace App\Actions;

use App\Enums\ShiftStatus;
use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\BeginningSaldo;
use App\Models\ShiftTemplate;
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
            // Check if drawer is active
            if (!$drawer->is_active) {
                throw ValidationException::withMessages([
                    'drawer' => 'This cash drawer is not active.'
                ]);
            }

            // Use raw SQL to check for existing open shifts with proper locking
            $existingShift = DB::selectOne("
                SELECT id, cash_drawer_id, user_id, status 
                FROM cashier_shifts 
                WHERE user_id = ? AND cash_drawer_id = ? AND status = ? 
                FOR UPDATE
            ", [$user->id, $drawer->id, ShiftStatus::OPEN->value]);

            if ($existingShift) {
                throw ValidationException::withMessages([
                    'shift' => "You already have an open shift on drawer '{$drawer->name}'. Please close it before starting a new shift."
                ]);
            }

            // Also check for any open shift by this user across all drawers
            $anyExistingShift = DB::selectOne("
                SELECT id, cash_drawer_id 
                FROM cashier_shifts 
                WHERE user_id = ? AND status = ? 
                FOR UPDATE
            ", [$user->id, ShiftStatus::OPEN->value]);

            if ($anyExistingShift) {
                // Get drawer name for better error message
                $drawerName = CashDrawer::find($anyExistingShift->cash_drawer_id)?->name ?? 'Unknown';
                throw ValidationException::withMessages([
                    'shift' => "You already have an open shift on drawer '{$drawerName}'. Please close it before starting a new shift."
                ]);
            }

            // Create the shift with constraint violation handling
            try {
                $shift = CashierShift::create([
                    'cash_drawer_id' => $drawer->id,
                    'user_id' => $user->id,
                    'status' => ShiftStatus::OPEN,
                    'beginning_saldo' => $validated['beginning_saldo'] ?? $validated['beginning_saldo_uzs'] ?? 0,
                    'opened_at' => now(),
                    'notes' => $validated['notes'] ?? null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation
                    // Try to find the conflicting shift for better error message
                    $conflictingShift = DB::selectOne("
                        SELECT id, cash_drawer_id 
                        FROM cashier_shifts 
                        WHERE user_id = ? AND cash_drawer_id = ? AND status = ?
                    ", [$user->id, $drawer->id, ShiftStatus::OPEN->value]);
                    
                    if ($conflictingShift) {
                        throw ValidationException::withMessages([
                            'shift' => "Unable to start shift. You already have an open shift on drawer '{$drawer->name}' (Shift ID: {$conflictingShift->id}). Please close it first."
                        ]);
                    }
                    
                    throw ValidationException::withMessages([
                        'shift' => "Unable to start shift. You may already have an open shift. Please check your existing shifts."
                    ]);
                }
                throw $e;
            }

            // Create beginning saldos for each currency
            $currencies = [
                'uzs' => Currency::UZS,
                'usd' => Currency::USD,
                'eur' => Currency::EUR,
                'rub' => Currency::RUB,
            ];

            foreach ($currencies as $key => $currency) {
                // Use provided amount or get from shift template
                $amount = $validated["beginning_saldo_{$key}"] ?? $this->getTemplateAmount($drawer, $currency);
                
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

    /**
     * Get template amount for a currency from previous shift (if no discrepancies)
     */
    protected function getTemplateAmount(CashDrawer $drawer, Currency $currency): float
    {
        $template = ShiftTemplate::where('cash_drawer_id', $drawer->id)
            ->where('currency', $currency)
            ->where('has_discrepancy', false)
            ->first();

        return $template ? $template->amount : 0;
    }
}

