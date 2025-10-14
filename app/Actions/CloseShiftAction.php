<?php

namespace App\Actions;

use App\Enums\Currency;
use App\Enums\ShiftStatus;
use App\Models\CashCount;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CloseShiftAction
{
    /**
     * Simple close a shift without cash count (for shifts with no transactions)
     */
    public function simpleClose(CashierShift $shift, User $user, string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $user, $notes) {
            // Check if shift is open
            if (!$shift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'This shift is already closed.'
                ]);
            }

            // Simply close the shift
            $shift->update([
                'status' => ShiftStatus::CLOSED,
                'closed_at' => now(),
                'notes' => $notes ?? $shift->notes ?? 'Closed via Telegram - No transactions',
            ]);

            return $shift->fresh();
        });
    }

    /**
     * Close a shift with multi-currency cash count and discrepancy handling
     */
    public function execute(CashierShift $shift, User $user, array $data): CashierShift
    {
        $validated = Validator::make($data, [
            'counted_end_saldos' => 'required|array|min:1',
            'counted_end_saldos.*.currency' => 'required|string|in:UZS,USD,EUR,RUB',
            'counted_end_saldos.*.counted_end_saldo' => 'required|numeric',
            'counted_end_saldos.*.denominations' => 'nullable|array',
            'counted_end_saldos.*.denominations.*.denomination' => 'required|numeric|min:0.01',
            'counted_end_saldos.*.denominations.*.qty' => 'required|integer|min:0',
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

            // Get all currencies used in this shift
            $usedCurrencies = $shift->getUsedCurrencies();
            $beginningSaldoCurrencies = $shift->beginningSaldos->pluck('currency');
            $allCurrencies = $usedCurrencies->merge($beginningSaldoCurrencies)->unique();

            // Validate that all currencies have counted end saldos
            $providedCurrencies = collect($validated['counted_end_saldos'])->pluck('currency');
            foreach ($allCurrencies as $currency) {
                if (!$providedCurrencies->contains($currency->value)) {
                    throw ValidationException::withMessages([
                        'counted_end_saldos' => "Counted end saldo is required for {$currency->value} currency."
                    ]);
                }
            }

            // Process each currency
            $hasDiscrepancy = false;
            foreach ($validated['counted_end_saldos'] as $currencyData) {
                $currency = Currency::from($currencyData['currency']);
                $expectedEndSaldo = $shift->getNetBalanceForCurrency($currency);
                $countedEndSaldo = $currencyData['counted_end_saldo'];
                $discrepancy = $countedEndSaldo - $expectedEndSaldo;

                // Validate denominations total matches counted saldo (if provided)
                if (!empty($currencyData['denominations'])) {
                    $denominationsTotal = 0;
                    foreach ($currencyData['denominations'] as $denomination) {
                        $denominationsTotal += $denomination['denomination'] * $denomination['qty'];
                    }

                    if (abs($denominationsTotal - $countedEndSaldo) > 0.01) {
                        throw ValidationException::withMessages([
                            'counted_end_saldos' => "Denominations total does not match counted end saldo for {$currency->value}."
                        ]);
                    }
                }

                // Create or update end saldo record
                EndSaldo::updateOrCreate(
                    [
                        'cashier_shift_id' => $shift->id,
                        'currency' => $currency,
                    ],
                    [
                        'expected_end_saldo' => $expectedEndSaldo,
                        'counted_end_saldo' => $countedEndSaldo,
                        'discrepancy' => $discrepancy,
                        'discrepancy_reason' => abs($discrepancy) > 0.01 ? ($validated['discrepancy_reason'] ?? null) : null,
                    ]
                );

                if (abs($discrepancy) > 0.01) {
                    $hasDiscrepancy = true;
                }
            }

            // Require discrepancy reason if there's any discrepancy
            if ($hasDiscrepancy && empty($validated['discrepancy_reason'])) {
                throw ValidationException::withMessages([
                    'discrepancy_reason' => 'Discrepancy reason is required when there is a discrepancy.'
                ]);
            }

            // Update the shift status (UNDER_REVIEW if discrepancy, CLOSED otherwise)
            $shift->update([
                'status' => $hasDiscrepancy ? ShiftStatus::UNDER_REVIEW : ShiftStatus::CLOSED,
                'closed_at' => now(),
                'notes' => $validated['notes'] ?? $shift->notes,
                'discrepancy_reason' => $hasDiscrepancy ? $validated['discrepancy_reason'] : null,
            ]);

            // Create cash count records for currencies with denominations
            foreach ($validated['counted_end_saldos'] as $currencyData) {
                if (!empty($currencyData['denominations'])) {
                    $currency = Currency::from($currencyData['currency']);
                    $countedEndSaldo = $currencyData['counted_end_saldo'];
                    
                    CashCount::create([
                        'cashier_shift_id' => $shift->id,
                        'currency' => $currency,
                        'denominations' => $currencyData['denominations'],
                        'total' => $countedEndSaldo,
                        'notes' => $validated['notes'] ?? null,
                    ]);
                }
            }

            // Update drawer balances with counted amounts
            $this->updateDrawerBalances($shift);

            // Create shift templates for next shift (only if no discrepancies)
            $this->createShiftTemplates($shift, $hasDiscrepancy);

            return $shift->fresh();
        });
    }

    /**
     * Update drawer balances with counted end saldos
     */
    protected function updateDrawerBalances(CashierShift $shift): void
    {
        $drawer = $shift->cashDrawer;
        $balances = [];

        foreach ($shift->endSaldos as $endSaldo) {
            $balances[$endSaldo->currency->value] = (float) $endSaldo->counted_end_saldo;
        }

        $drawer->balances = $balances;
        $drawer->save();
    }

    /**
     * Create shift templates for the next shift based on current end saldos
     */
    protected function createShiftTemplates(CashierShift $shift, bool $hasDiscrepancy): void
    {
        $endSaldos = $shift->endSaldos;

        foreach ($endSaldos as $endSaldo) {
            ShiftTemplate::updateOrCreate(
                [
                    'cash_drawer_id' => $shift->cash_drawer_id,
                    'currency' => $endSaldo->currency,
                ],
                [
                    'amount' => $endSaldo->counted_end_saldo,
                    'last_shift_id' => $shift->id,
                    'has_discrepancy' => $hasDiscrepancy,
                ]
            );
        }
    }
}


