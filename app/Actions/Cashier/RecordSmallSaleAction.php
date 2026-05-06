<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Enums\CashTransactionSource;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\IncomeCategory;

/**
 * Records a single petty / small sale from the admin Filament panel
 * AND from the cashier Telegram bot's confirm_sale flow.
 *
 * Doctrine mirroring the cashier bot's existing 'sale' rows:
 *   - type = 'in'
 *   - category = 'sale'   (broad bucket, kept for back-compat)
 *   - income_category_id  (new fine-grained taxonomy)
 *   - source_trigger      stamps which surface created the row so the
 *                         drawer-truth scope counts it. Without this
 *                         the DB column default 'beds24_external' kicks
 *                         in (migration 2026_03_29_100002 set that
 *                         default for legacy backfill) and the row is
 *                         silently excluded from BalanceCalculator —
 *                         real incident 2026-05-06: Doniyor's $10
 *                         petty sale never moved the drawer balance.
 *   - cashier_shift_id    nullable — when an open shift exists we attach
 *                         to it so the cashier's running balance reflects
 *                         the sale; otherwise admin-attributed (FK NULL).
 *   - created_by          stamped from auth() so the audit trail names
 *                         the admin/cashier who recorded it.
 *
 * Why this lives in app/Actions: avoids embedding business logic inside
 * Filament closures (CLAUDE.md hard line). Same path is reachable from
 * CLI / future API / bot flow without duplicating logic.
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
     * @param  CashTransactionSource  $sourceTrigger  Which surface is calling
     *         this action. Defaults to CashierBot because the bot's
     *         confirm_sale handler is the highest-volume call site;
     *         Filament/admin call sites MUST pass ManualAdmin explicitly.
     *         Beds24External is rejected — that source is exclusively
     *         for the Beds24 webhook bookkeeping path and would silently
     *         exclude petty-sale rows from drawer truth.
     */
    public function execute(
        array $data,
        ?int $createdByUserId = null,
        CashTransactionSource $sourceTrigger = CashTransactionSource::CashierBot,
    ): CashTransaction {
        // Reject Beds24External — petty-sale rows must be drawer truth,
        // and Beds24External is the one source explicitly excluded by
        // CashTransactionSource::isDrawerTruth().
        if ($sourceTrigger === CashTransactionSource::Beds24External) {
            throw new \InvalidArgumentException(
                'RecordSmallSaleAction cannot tag a row as beds24_external; '
                . 'that source is reserved for the Beds24 webhook path '
                . 'and is excluded from drawer truth.'
            );
        }

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
            'source_trigger'     => $sourceTrigger->value,
            'created_by'         => $createdByUserId,
            'occurred_at'        => now(),
        ]);
    }
}
