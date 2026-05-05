<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\IncomeCategory;

/**
 * Records a single petty / small sale from the admin Filament panel.
 *
 * Doctrine mirroring the cashier bot's existing 'sale' rows:
 *   - type = 'in'
 *   - category = 'sale'   (broad bucket, kept for back-compat)
 *   - income_category_id  (new fine-grained taxonomy)
 *   - cashier_shift_id    nullable — when an open shift exists we attach
 *                         to it so the cashier's running balance reflects
 *                         the sale; otherwise admin-attributed (FK NULL).
 *   - created_by          stamped from auth() so the audit trail names
 *                         the admin who recorded it.
 *
 * Why this lives in app/Actions: avoids embedding business logic inside
 * Filament closures (CLAUDE.md hard line). Same path is reachable from
 * CLI / future API / future bot flow without duplicating logic.
 */
class RecordSmallSaleAction
{
    /**
     * @param array{
     *   amount: float|string,
     *   currency: string,
     *   income_category_id: int,
     *   payment_method?: string,
     *   notes?: ?string,
     * } $data
     */
    public function execute(array $data, ?int $createdByUserId = null): CashTransaction
    {
        // Attach to current open shift if any. Latest-opened wins —
        // matches BalanceCalculator::getShift semantics.
        $openShift = CashierShift::where('status', 'open')
            ->latest('opened_at')
            ->first();

        // Verify the income category exists + is active. We trust
        // Filament validation but keep a server-side check so this
        // Action stays safe to call from non-UI surfaces.
        $category = IncomeCategory::where('id', $data['income_category_id'])
            ->where('is_active', true)
            ->firstOrFail();

        return CashTransaction::create([
            'cashier_shift_id'   => $openShift?->id,
            'type'               => 'in',
            'amount'             => $data['amount'],
            'currency'           => $data['currency'],
            'category'           => 'sale',
            'income_category_id' => $category->id,
            'payment_method'     => $data['payment_method'] ?? 'cash',
            'notes'              => isset($data['notes']) && $data['notes'] !== '' ? $data['notes'] : null,
            'created_by'         => $createdByUserId,
            'occurred_at'        => now(),
        ]);
    }
}
