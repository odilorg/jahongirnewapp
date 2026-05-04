<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\DTO\RecordPaymentData;
use App\Models\CashierShift;
use App\Services\BotPaymentService;

/**
 * Phase 1.5.1 — Admin Manual Mixed-Currency Journal Builder.
 *
 * Bridges Filament's form input → BotPaymentService::recordMixedCurrencySplitPayment.
 * Held in app/Actions so the same path is reachable from CLI, jobs, or
 * the future Phase 1.5.2 bot flow without duplicating logic.
 *
 * Responsibilities:
 *   1. Resolve shift + cashier id from the operator's chosen open shift
 *   2. Call BotPaymentService::preparePayment to get a fresh FROZEN FX
 *      snapshot (same path the bot uses — no special "admin path" rates)
 *   3. Build two RecordPaymentData DTOs, one per leg, both sharing the
 *      same frozen presentation
 *   4. Hand off to BotPaymentService::recordMixedCurrencySplitPayment
 *      which enforces sum-lock + journal_entry_id + group type + status
 *
 * Returns the journal UUID and both transaction IDs for the operator
 * notification.
 */
class RecordMixedCurrencySplitFromAdminAction
{
    public function __construct(
        private BotPaymentService $botPaymentService,
    ) {}

    /**
     * @param array{
     *   cashier_shift_id: int,
     *   beds24_booking_id: string,
     *   base_currency: string,
     *   leg1_currency: string, leg1_amount: float, leg1_method: string,
     *   leg2_currency: string, leg2_amount: float, leg2_method: string,
     * } $data
     *
     * @return array{journal_uuid: string, tx1_id: int, tx2_id: int}
     */
    public function execute(array $data): array
    {
        $shift = CashierShift::findOrFail((int) $data['cashier_shift_id']);
        if (! $shift->isOpen()) {
            throw new \InvalidArgumentException("Shift #{$shift->id} is not open.");
        }

        // One frozen presentation shared by both legs — single FX snapshot
        // per journal. botSessionId labels this as an admin-originated entry
        // so logs distinguish it from cashier-bot recordings.
        $botSessionId = sprintf('admin:%d:%d:%d', auth()->id() ?? 0, $shift->id, time());
        $presentation = $this->botPaymentService->preparePayment(
            (string) $data['beds24_booking_id'],
            $botSessionId,
        );

        $cashierId = (int) ($shift->user_id ?? auth()->id());

        $leg1 = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         $shift->id,
            cashierId:       $cashierId,
            currencyPaid:    (string) $data['leg1_currency'],
            amountPaid:      (float)  $data['leg1_amount'],
            paymentMethod:   (string) $data['leg1_method'],
            overrideReason:  null,
            managerApproval: null,
        );

        $leg2 = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         $shift->id,
            cashierId:       $cashierId,
            currencyPaid:    (string) $data['leg2_currency'],
            amountPaid:      (float)  $data['leg2_amount'],
            paymentMethod:   (string) $data['leg2_method'],
            overrideReason:  null,
            managerApproval: null,
        );

        [$tx1, $tx2] = $this->botPaymentService->recordMixedCurrencySplitPayment(
            $leg1,
            $leg2,
            (string) $data['base_currency'],
        );

        return [
            'journal_uuid' => (string) $tx1->journal_entry_id,
            'tx1_id'       => (int) $tx1->id,
            'tx2_id'       => (int) $tx2->id,
        ];
    }
}
