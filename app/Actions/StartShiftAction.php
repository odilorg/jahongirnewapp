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
     * Quick start a shift with auto-detection and carry-over balances
     * This is the ONE-CLICK method that does everything automatically
     */
    public function quickStart(User $user): CashierShift
    {
        return DB::transaction(function () use ($user) {
            // 1. Auto-select drawer based on user's assigned locations
            $drawer = $this->autoSelectDrawer($user);

            if (!$drawer) {
                throw ValidationException::withMessages([
                    'location' => 'You are not assigned to any locations. Please contact your manager.'
                ]);
            }

            // 2. Get previous shift's ending balances
            $previousShift = $this->getPreviousShift($drawer);

            // 3. Prepare beginning balances (carry over from previous or use defaults)
            $beginningBalances = $this->prepareBeginningBalances($drawer, $previousShift);

            // 4. Start the shift
            return $this->execute($user, $drawer, $beginningBalances);
        });
    }

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

            // Prevent multiple open shifts on the same drawer
            $drawerOpenShift = CashierShift::query()
                ->where('cash_drawer_id', $drawer->id)
                ->where('status', ShiftStatus::OPEN)
                ->first();

            if ($drawerOpenShift) {
                throw ValidationException::withMessages([
                    'cash_drawer_id' => "Drawer '{$drawer->name}' already has an open shift (Shift #{$drawerOpenShift->id}). Close the existing shift before opening another.",
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

            // Load relationships for proper access
            $shift->load('cashDrawer.location', 'beginningSaldos');

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

    /**
     * Auto-select drawer based on user's assigned locations
     * Priority: If user has only 1 location, auto-select a drawer from that location
     */
    protected function autoSelectDrawer(User $user): ?CashDrawer
    {
        // Get user's assigned locations
        $locations = $user->locations;

        if ($locations->isEmpty()) {
            return null;
        }

        // If user has only 1 location, auto-select the first active drawer
        if ($locations->count() === 1) {
            $location = $locations->first();
            return CashDrawer::with('location')
                ->where('location_id', $location->id)
                ->where('is_active', true)
                ->whereDoesntHave('openShifts') // No open shifts
                ->first();
        }

        // If multiple locations, try to find any available drawer
        // Prefer drawers with no open shifts
        return CashDrawer::with('location')
            ->whereIn('location_id', $locations->pluck('id'))
            ->where('is_active', true)
            ->whereDoesntHave('openShifts')
            ->first();
    }

    /**
     * Get the most recent closed shift for this drawer
     */
    protected function getPreviousShift(CashDrawer $drawer): ?CashierShift
    {
        return CashierShift::where('cash_drawer_id', $drawer->id)
            ->where('status', ShiftStatus::CLOSED)
            ->orderBy('closed_at', 'desc')
            ->with('endSaldos')
            ->first();
    }

    /**
     * Prepare beginning balances from previous shift or use defaults
     */
    protected function prepareBeginningBalances(CashDrawer $drawer, ?CashierShift $previousShift): array
    {
        $balances = [];

        $currencies = ['uzs', 'usd', 'eur', 'rub'];
        $currencyEnums = [
            'uzs' => Currency::UZS,
            'usd' => Currency::USD,
            'eur' => Currency::EUR,
            'rub' => Currency::RUB,
        ];

        if ($previousShift && $previousShift->endSaldos->isNotEmpty()) {
            // Carry over from previous shift's ending balances
            foreach ($currencies as $key) {
                $currency = $currencyEnums[$key];
                $endSaldo = $previousShift->endSaldos->where('currency', $currency)->first();

                if ($endSaldo) {
                    $balances["beginning_saldo_{$key}"] = $endSaldo->counted_end_saldo ?? $endSaldo->expected_end_saldo;
                } else {
                    $balances["beginning_saldo_{$key}"] = 0;
                }
            }
        } else {
            // Use drawer's current balances or defaults
            $drawerBalances = $drawer->balances ?? [];

            foreach ($currencies as $key) {
                $currency = $currencyEnums[$key];
                $balances["beginning_saldo_{$key}"] = $drawerBalances[$currency->value] ?? 0;
            }
        }

        return $balances;
    }
}

