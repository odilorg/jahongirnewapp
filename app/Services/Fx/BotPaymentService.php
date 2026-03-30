<?php

namespace App\Services\Fx;

use App\DTOs\Fx\PreparedPayment;
use App\DTOs\Fx\RecordPaymentData;
use App\Enums\CashTransactionSource;
use App\Enums\FxSourceTrigger;
use App\Enums\OverrideTier;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\Fx\PaymentBlockedException;
use App\Exceptions\Fx\StalePaymentSessionException;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use App\Models\BookingFxSync;
use App\Models\CashTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the full bot payment lifecycle:
 *   1. preparePayment()  — build/refresh FX snapshot, return amounts to show cashier
 *   2. recordPayment()   — atomic insert of CashTransaction + Beds24PaymentSync + approval consume
 *
 * Feature flag: FX_BOT_PAYMENT_V2 must be enabled for this service to be used.
 */
class BotPaymentService
{
    public function __construct(
        private readonly FxSyncService             $fxSync,
        private readonly SettlementCalculator      $settlement,
        private readonly OverridePolicyEvaluator   $overridePolicy,
        private readonly FxManagerApprovalService  $approvalService,
        private readonly Beds24PaymentSyncService  $syncService,
    ) {}

    /**
     * Phase 1 of the bot flow: build the presentation the cashier sees.
     *
     * @param  string  $beds24BookingId
     * @param  float   $usdAmount        Booking total from Beds24
     * @param  Carbon  $arrivalDate
     * @param  string  $guestName
     * @param  string  $roomNumber
     */
    public function preparePayment(
        string $beds24BookingId,
        float  $usdAmount,
        Carbon $arrivalDate,
        string $guestName,
        string $roomNumber,
    ): PreparedPayment {
        $presentation = $this->fxSync->getOrRefresh(
            beds24BookingId: $beds24BookingId,
            usdAmount:       $usdAmount,
            arrivalDate:     $arrivalDate,
            guestName:       $guestName,
            roomNumber:      $roomNumber,
            trigger:         FxSourceTrigger::Bot,
        );

        $remaining = $this->settlement->remaining($beds24BookingId, $usdAmount);

        return new PreparedPayment($presentation, $remaining);
    }

    /**
     * Phase 2 of the bot flow: atomically record the payment.
     *
     * Atomic boundary (single DB transaction):
     *   - FOR UPDATE lock on booking_fx_syncs row
     *   - Stale-session guard
     *   - Override policy check
     *   - Insert CashTransaction
     *   - Insert Beds24PaymentSync
     *   - Consume manager approval (if applicable)
     *   - Beds24PaymentSyncJob dispatched via DB::afterCommit()
     *
     * @throws StalePaymentSessionException
     * @throws PaymentBlockedException
     */
    public function recordPayment(RecordPaymentData $data): CashTransaction
    {
        return DB::transaction(function () use ($data) {
            // Lock the FX snapshot row to prevent concurrent payment inserts
            $fxSync = BookingFxSync::where('beds24_booking_id', $data->beds24BookingId)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard: presentation must not be stale
            if ($data->presentation->isStale()) {
                throw new StalePaymentSessionException(
                    'Payment presentation has expired. Please restart the flow to get fresh rates.'
                );
            }

            // Evaluate override tier for the amount the cashier entered
            $presentedAmount = $data->presentation->amountFor($data->paidCurrency);
            $evaluation      = $this->overridePolicy->evaluate(
                currency:        $data->paidCurrency,
                presentedAmount: $presentedAmount,
                proposedAmount:  $data->paidAmount,
            );

            if ($evaluation->isBlocked()) {
                throw new PaymentBlockedException(
                    "Payment blocked: {$evaluation->variancePct}% variance exceeds maximum allowed. "
                    . "Presented: {$presentedAmount}, Proposed: {$data->paidAmount}."
                );
            }

            // Manager-tier override requires a pre-approved approval row
            if ($evaluation->tier === OverrideTier::Manager) {
                if (! $data->overrideApprovalId) {
                    throw new PaymentBlockedException(
                        'Manager approval required but no approval ID provided.'
                    );
                }
                // Validate the approval before inserting the transaction
                $approval = $this->approvalService->findConsumable(
                    $data->overrideApprovalId,
                    $data->beds24BookingId,
                );
            }

            // Calculate USD equivalent for drawer reconciliation
            $usdEquivalent = $this->resolveUsdEquivalent($data, $fxSync);

            // Insert the CashTransaction
            $transaction = CashTransaction::create([
                'cashier_shift_id'           => $data->cashierShiftId,
                'type'                        => TransactionType::IN->value,
                'amount'                      => $data->paidAmount,
                'currency'                    => $data->paidCurrency->value,
                'category'                    => TransactionCategory::SALE->value,
                'beds24_booking_id'           => $data->beds24BookingId,
                'payment_method'              => $data->paymentMethod,
                'guest_name'                  => $data->presentation->guestName,
                'room_number'                 => $data->presentation->roomNumber,
                'created_by'                  => $data->createdBy,
                'occurred_at'                 => now(),

                // FX additions
                'source_trigger'              => CashTransactionSource::CashierBot->value,
                'booking_fx_sync_id'          => $fxSync->id,
                'exchange_rate_id'            => $data->presentation->exchangeRateId,

                // Presentation snapshot (frozen)
                'amount_presented_uzs'        => $data->presentation->uzsAmount,
                'amount_presented_eur'        => $data->presentation->eurAmount,
                'amount_presented_rub'        => $data->presentation->rubAmount,
                'amount_presented_usd'        => $data->presentation->usdAmount,
                'presented_currency'          => $data->paidCurrency->value,
                'amount_presented_selected'   => $presentedAmount,

                'usd_equivalent_paid'         => $usdEquivalent,

                // Override fields
                'is_override'                 => ! $evaluation->withinTolerance,
                'within_tolerance'            => $evaluation->withinTolerance,
                'variance_pct'                => $evaluation->variancePct,
                'override_tier'               => $evaluation->tier->value,
                'override_reason'             => $data->overrideReason,
                'override_approval_id'        => $data->overrideApprovalId,

                // Session fields
                'presented_at'                => $data->presentation->preparedAt,
                'recorded_at'                 => now(),
                'bot_session_id'              => $data->botSessionId,
            ]);

            // Insert the Beds24PaymentSync row (sets up the [ref:UUID] idempotency anchor)
            $syncRow = $this->syncService->createPending($transaction, $usdEquivalent);

            // Back-link from transaction to the sync row
            $transaction->update([
                'beds24_payment_sync_id' => $syncRow->id,
                'beds24_payment_ref'     => $syncRow->local_reference,
            ]);

            // Consume manager approval if required (single-use invariant)
            if (isset($approval)) {
                $this->approvalService->consume($approval, $transaction->id);
                $transaction->update(['override_approved_at' => now()]);
            }

            // Dispatch push job ONLY after the transaction commits —
            // prevents the job from running against a not-yet-visible row.
            if (config('features.beds24_auto_push_payment', false)) {
                DB::afterCommit(function () use ($syncRow) {
                    Beds24PaymentSyncJob::dispatch($syncRow->id);
                });
            }

            return $transaction;
        });
    }

    // -----------------------------------------------------------------------

    private function resolveUsdEquivalent(RecordPaymentData $data, BookingFxSync $fxSync): float
    {
        // USD paid directly
        if ($data->paidCurrency->value === 'USD') {
            return (float) $data->paidAmount;
        }

        // Use the frozen snapshot rate: paidAmount / (snapshot_currency / snapshot_usd)
        $snapshotUsd = (float) $fxSync->usd_final;

        $snapshotInPaidCurrency = match ($data->paidCurrency->value) {
            'UZS' => (float) $fxSync->uzs_final,
            'EUR' => (float) $fxSync->eur_final,
            'RUB' => (float) $fxSync->rub_final,
            default => 0.0,
        };

        if ($snapshotInPaidCurrency <= 0 || $snapshotUsd <= 0) {
            return 0.0;
        }

        // Implicit rate: snapshot_usd / snapshot_paid_currency
        return round((float) $data->paidAmount * ($snapshotUsd / $snapshotInPaidCurrency), 2);
    }
}
