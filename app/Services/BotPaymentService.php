<?php

namespace App\Services;

use App\DTO\GroupAmountResolution;
use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\DuplicateGroupPaymentException;
use App\Exceptions\IncompleteGroupSyncException;
use App\Services\Fx\OverridePolicyEvaluator as FxOverridePolicyEvaluator;
use App\Exceptions\ManagerApprovalRequiredException;
use App\Exceptions\PaymentBlockedException;
use App\Exceptions\StalePaymentSessionException;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\CashTransaction;
use App\Services\Cashier\GroupAwareCashierAmountResolver;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cashier bot payment flow — active FX payment path used by CashierBotController.
 *
 * @deprecated Superseded by App\Services\Fx\BotPaymentService. This class remains
 *             active until the controller is migrated to the Fx namespace. Do not
 *             add new business logic here — fix the canonical service instead.
 *             Tracked: TODO(payment-orchestrator) — collapse to single payment path.
 *
 * Core rule: this service never calculates exchange rates. It reads from
 * booking_fx_syncs (via FxSyncService) and records against a frozen snapshot.
 */
class BotPaymentService
{
    public function __construct(
        private readonly FxSyncService                  $fxSync,
        private readonly FxOverridePolicyEvaluator      $overridePolicy,
        private readonly FxManagerApprovalService       $approvalService,
        private readonly Beds24PaymentSyncService       $syncService,
        private readonly GroupAwareCashierAmountResolver $groupResolver,
    ) {}

    // -------------------------------------------------------------------------
    // Step 1: Prepare
    // -------------------------------------------------------------------------

    /**
     * Resolve booking by Beds24 booking ID, ensure FX sync is fresh, return frozen DTO.
     *
     * For group bookings (multiple rooms, one guest), the FX sync is recalculated
     * against the *group total* USD amount rather than the single-room amount.
     * The resolver handles on-demand sibling fetch if local sync is incomplete.
     *
     * $botSessionId should uniquely identify this Telegram conversation, e.g.:
     *   "{$chatId}:{$messageId}" or a UUID stored in TelegramPosSession.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \App\Exceptions\Beds24RateLimitException
     * @throws IncompleteGroupSyncException — group booking cannot be fully resolved
     */
    public function preparePayment(string $beds24BookingId, string $botSessionId): PaymentPresentation
    {
        // IMPORTANT: query by beds24_booking_id (external Beds24 ID), NOT by local model PK (id)
        $booking = Beds24Booking::where('beds24_booking_id', $beds24BookingId)->firstOrFail();

        // Resolve group-aware USD amount (standalone → per-room; grouped → group total)
        $resolution = $this->groupResolver->resolve($booking);

        // Get or create FX sync for the entered booking ID.
        // If the stored usd_amount_used doesn't match the resolved group total
        // (e.g., sync was calculated before this booking was linked to its group),
        // force a re-push with the correct amount.
        $sync = $this->fxSync->ensureFresh($booking, 'bot');

        if (abs((float) $sync->usd_amount_used - $resolution->usdAmount) > 0.01) {
            Log::info('BotPaymentService: group amount differs from stored sync — re-pushing', [
                'beds24_booking_id'  => $beds24BookingId,
                'stored_usd_amount'  => $sync->usd_amount_used,
                'resolved_usd_amount' => $resolution->usdAmount,
                'is_group'           => ! $resolution->isSingleBooking,
            ]);
            $sync = $this->fxSync->pushNow($booking, 'bot', $resolution->usdAmount);
        }

        return PaymentPresentation::fromSync($booking, $sync, $botSessionId, $resolution);
    }

    // -------------------------------------------------------------------------
    // Step 2: Record
    // -------------------------------------------------------------------------

    /**
     * Record a payment against the frozen PaymentPresentation snapshot.
     *
     * Validations (in order):
     *  1. Presentation not expired (> TTL_MINUTES old)
     *  2. Override tier — Blocked throws, Manager requires approval
     *  3. Duplicate group payment guard (for grouped bookings)
     *  4. Booking still payable (not cancelled mid-conversation)
     *  5. Manager approval still 'approved' (lockForUpdate, then mark consumed)
     *  6. Record CashTransaction atomically with group audit metadata
     *
     * @throws StalePaymentSessionException
     * @throws PaymentBlockedException
     * @throws ManagerApprovalRequiredException
     * @throws BookingNotPayableException
     * @throws DuplicateGroupPaymentException
     */
    public function recordPayment(RecordPaymentData $data): CashTransaction
    {
        // 1. Session expiry — reject stale conversations
        if ($data->presentation->isExpired()) {
            throw new StalePaymentSessionException(
                'Payment session expired after ' . PaymentPresentation::TTL_MINUTES . ' minutes. Please start again.'
            );
        }

        // 2. Override policy — use canonical Fx evaluator (returns Blocked when threshold exceeded)
        $presented  = $data->presentation->presentedAmountFor($data->currencyPaid);
        $currency   = Currency::tryFrom(strtoupper($data->currencyPaid)) ?? Currency::UZS;
        $evaluation = $this->overridePolicy->evaluate($currency, $presented, $data->amountPaid);
        $tier       = $evaluation->tier;

        if ($evaluation->isBlocked()) {
            throw new PaymentBlockedException(
                'Variance exceeds maximum allowed. Escalate to management offline.'
            );
        }

        if ($tier === OverrideTier::Manager && ! $data->managerApproval) {
            throw new ManagerApprovalRequiredException(
                'This override requires manager approval via Telegram.'
            );
        }

        // 3. Duplicate group payment guard (before entering DB transaction)
        $this->guardAgainstDuplicateGroupPayment($data->presentation);

        // Soft guard — log if booking already fully paid (does not block)
        $this->warnIfAlreadyFullyPaid($data->presentation, $data->currencyPaid);

        return DB::transaction(function () use ($data, $tier, $presented): CashTransaction {
            $p = $data->presentation; // frozen — never re-reads live sync

            // 4. Booking still payable (check inside transaction)
            // Query by beds24_booking_id, NOT by local PK
            $booking = Beds24Booking::where('beds24_booking_id', $p->beds24BookingId)->first();
            if (! $booking || ! $booking->isPayable()) {
                throw new BookingNotPayableException(
                    "Booking #{$p->beds24BookingId} is no longer in a payable state."
                );
            }

            // 5. Consume manager approval atomically (lockForUpdate inside consume())
            if ($data->managerApproval) {
                // consume() re-locks and verifies status is still 'approved' before marking consumed
                // placeholder — we link after creating transaction below
            }

            // Retrieve sync row to compute USD equivalent for Beds24 push
            $fxSync = BookingFxSync::find($p->syncId);
            $usdEquivalent = $this->resolveUsdEquivalent($data->amountPaid, $data->currencyPaid, $fxSync);

            // 6. Create cash transaction with group audit metadata
            $transaction = CashTransaction::create([
                // Core transaction fields
                'cashier_shift_id'            => $data->shiftId,
                'type'                        => 'in',
                'amount'                      => $data->amountPaid,
                'currency'                    => $data->currencyPaid,
                'category'                    => 'sale',
                'beds24_booking_id'           => $p->beds24BookingId,
                'payment_method'              => $data->paymentMethod,
                'guest_name'                  => $p->guestName,
                'reference'                   => "Beds24 #{$p->beds24BookingId}",
                'notes'                       => $this->buildNotes($p, $data, $tier, $presented),
                'created_by'                  => $data->cashierId,
                'occurred_at'                 => now(),

                // FX presentation audit columns
                'booking_fx_sync_id'          => $p->syncId,
                'daily_exchange_rate_id'      => $p->dailyExchangeRateId,
                'amount_presented_uzs'        => $p->uzsPresented,
                'amount_presented_eur'        => $p->eurPresented,
                'amount_presented_rub'        => $p->rubPresented,
                'presented_currency'          => $data->currencyPaid,
                'amount_presented_selected'   => $presented,
                'usd_equivalent_paid'         => $usdEquivalent,
                'is_override'                 => $tier !== OverrideTier::None,
                'override_tier'               => $tier->value,
                'override_reason'             => $data->overrideReason,
                'override_approved_by'        => $data->managerApproval?->resolved_by,
                'override_approved_at'        => $data->managerApproval?->resolved_at,
                'presented_at'                => $p->presentedAt,
                'recorded_at'                 => now(),
                'bot_session_id'              => $p->botSessionId,
                'source_trigger'              => 'cashier_bot',

                // Group booking audit — null for standalone bookings
                'group_master_booking_id'     => $p->groupMasterBookingId,
                'is_group_payment'            => $p->isGroupPayment,
                'group_size_expected'         => $p->groupSizeExpected,
                'group_size_local'            => $p->groupSizeLocal,
            ]);

            // Create Beds24PaymentSync row — queued job pushes payment to Beds24 after commit.
            $syncRow = $this->syncService->createPending($transaction, $usdEquivalent);
            $transaction->update([
                'beds24_payment_sync_id' => $syncRow->id,
                'beds24_payment_ref'     => $syncRow->local_reference,
            ]);

            // Consume approval now that we have the transaction ID
            if ($data->managerApproval) {
                $this->approvalService->consume($data->managerApproval, $transaction->id);
            }

            // Dispatch push job only after DB commit so the row is visible to the worker
            if (config('features.beds24_auto_push_payment', false)) {
                DB::afterCommit(function () use ($syncRow) {
                    Beds24PaymentSyncJob::dispatch($syncRow->id);
                });
            }

            return $transaction;
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Guard against duplicate group payment.
     *
     * If the presentation carries a group_master_booking_id, check whether a
     * group payment already exists for that master — regardless of which sibling
     * booking ID was used to enter the payment. Blocks with DuplicateGroupPaymentException.
     *
     * Standalone bookings: no-op (guard only applies to grouped payments).
     */
    private function guardAgainstDuplicateGroupPayment(PaymentPresentation $p): void
    {
        if (! $p->isGroupPayment || $p->groupMasterBookingId === null) {
            return;
        }

        $exists = CashTransaction::where('group_master_booking_id', $p->groupMasterBookingId)
            ->where('is_group_payment', true)
            ->where('source_trigger', 'cashier_bot')
            ->exists();

        if ($exists) {
            throw new DuplicateGroupPaymentException(
                "A group payment has already been recorded for group master booking #{$p->groupMasterBookingId}. " .
                "Check the payment history before proceeding."
            );
        }
    }

    private function warnIfAlreadyFullyPaid(PaymentPresentation $p, string $currency): void
    {
        $expected  = $p->presentedAmountFor($currency);
        $collected = $this->effectiveCollected($p->beds24BookingId, $currency);

        if ($collected >= $expected) {
            Log::warning('fx-cashier: full payment already recorded for booking', [
                'beds24_booking_id' => $p->beds24BookingId,
                'currency'          => $currency,
                'expected'          => $expected,
                'already_collected' => $collected,
                'bot_session_id'    => $p->botSessionId,
            ]);
        }
    }

    /**
     * Compute the USD equivalent of the amount actually paid, using the FX snapshot.
     * Returns 0 when no snapshot is available — sync job will still run, amount will be 0.
     */
    private function resolveUsdEquivalent(float $amountPaid, string $currency, ?BookingFxSync $sync): float
    {
        if (strtoupper($currency) === 'USD') {
            return $amountPaid;
        }

        if (! $sync) {
            return 0.0;
        }

        $snapshotUsd        = (float) $sync->usd_final;
        $snapshotInCurrency = match (strtoupper($currency)) {
            'UZS'  => (float) $sync->uzs_final,
            'EUR'  => (float) $sync->eur_final,
            'RUB'  => (float) $sync->rub_final,
            default => 0.0,
        };

        if ($snapshotInCurrency <= 0 || $snapshotUsd <= 0) {
            return 0.0;
        }

        return round($amountPaid * ($snapshotUsd / $snapshotInCurrency), 2);
    }

    /**
     * Isolated query — add ->where('status', 'effective') here when reversals are introduced.
     */
    private function effectiveCollected(string $beds24BookingId, string $currency): float
    {
        return (float) CashTransaction::where('beds24_booking_id', $beds24BookingId)
            ->where('currency', $currency)
            ->sum('amount');
    }

    private function buildNotes(
        PaymentPresentation $p,
        RecordPaymentData   $data,
        OverrideTier        $tier,
        float               $presented,
    ): string {
        $notes = "Оплата: {$p->guestName} | Приезд: {$p->arrivalDate}";
        $notes .= "\nСумма по форме: " . number_format((int) $presented, 0, '.', ' ') . " {$data->currencyPaid}";
        $notes .= " | Курс: {$p->fxRateDate}";

        if ($p->isGroupPayment) {
            $notes .= "\n🏠 Группа (мастер #{$p->groupMasterBookingId}, {$p->groupSizeLocal}/{$p->groupSizeExpected} номеров)";
        }

        if ($tier !== OverrideTier::None) {
            $notes .= "\n⚠ Переопределение ({$tier->value}): {$data->overrideReason}";
        }

        return $notes;
    }
}
