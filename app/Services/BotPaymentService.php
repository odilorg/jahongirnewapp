<?php

namespace App\Services;

use App\DTO\GroupAmountResolution;
use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\CashTransactionSource;
use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\DuplicateGroupPaymentException;
use App\Exceptions\DuplicatePaymentException;
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
 * Cashier bot payment flow — canonical FX payment path used by CashierBotController.
 *
 * Core rule: this service never calculates exchange rates. It reads from
 * booking_fx_syncs (via FxSyncService) and records against a frozen snapshot
 * stored inside {@see PaymentPresentation}.
 *
 * L-002 (2026-04-18): collapsed from two divergent implementations
 * (Services\BotPaymentService + Services\Fx\BotPaymentService). This is now
 * the single source of truth. Writes the full audit superset to
 * cash_transactions (group fields + FX snapshot + override traceability).
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
     * @throws DuplicatePaymentException
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

        // Soft guard — log if booking already fully paid (does not block)
        $this->warnIfAlreadyFullyPaid($data->presentation, $data->currencyPaid);

        return DB::transaction(function () use ($data, $tier, $presented, $evaluation): CashTransaction {
            $p = $data->presentation; // frozen — never re-reads live sync

            // 3+4. Lock the booking row to serialize concurrent payment attempts.
            //
            // With lockForUpdate, a second request for the same booking waits until
            // the first transaction commits before acquiring the lock. At that point
            // the duplicate-payment guard below sees the committed row and throws,
            // preventing both standalone double-pays and group-payment races.
            $booking = Beds24Booking::where('beds24_booking_id', $p->beds24BookingId)
                ->lockForUpdate()
                ->first();

            if (! $booking || ! $booking->isPayable()) {
                throw new BookingNotPayableException(
                    "Booking #{$p->beds24BookingId} is no longer in a payable state."
                );
            }

            // Duplicate payment guard — runs under the booking row lock so it is
            // race-safe for both standalone and group payments.
            $this->guardAgainstDuplicatePayment($p);

            // 5. Consume manager approval atomically (lockForUpdate inside consume())
            if ($data->managerApproval) {
                // consume() re-locks and verifies status is still 'approved' before marking consumed
                // placeholder — we link after creating transaction below
            }

            // Retrieve sync row to compute USD equivalent for Beds24 push
            $fxSync = BookingFxSync::find($p->syncId);
            $usdEquivalent = $this->resolveUsdEquivalent($data->amountPaid, $data->currencyPaid, $fxSync);

            // 6. Create cash transaction with full audit superset (L-002 merge target)
            $transaction = CashTransaction::create([
                // Core transaction fields — enum values, not string literals
                'cashier_shift_id'            => $data->shiftId,
                'type'                        => TransactionType::IN->value,
                'amount'                      => $data->amountPaid,
                'currency'                    => $data->currencyPaid,
                'category'                    => TransactionCategory::SALE->value,
                'beds24_booking_id'           => $p->beds24BookingId,
                'payment_method'              => $data->paymentMethod,
                'guest_name'                  => $p->guestName,
                'room_number'                 => $p->roomNumber,
                'reference'                   => "Beds24 #{$p->beds24BookingId}",
                'notes'                       => $this->buildNotes($p, $data, $tier, $presented),
                'created_by'                  => $data->cashierId,
                'occurred_at'                 => now(),

                // FX presentation audit — both rate FKs populated for full traceability
                'booking_fx_sync_id'          => $p->syncId,
                'daily_exchange_rate_id'      => $p->dailyExchangeRateId,
                'exchange_rate_id'            => $p->exchangeRateId,
                'amount_presented_uzs'        => $p->uzsPresented,
                'amount_presented_eur'        => $p->eurPresented,
                'amount_presented_rub'        => $p->rubPresented,
                'amount_presented_usd'        => $p->usdPresented,
                'presented_currency'          => $data->currencyPaid,
                'amount_presented_selected'   => $presented,
                'usd_equivalent_paid'         => $usdEquivalent,

                // Override / approval traceability
                'is_override'                 => $tier !== OverrideTier::None,
                'within_tolerance'            => $evaluation->withinTolerance,
                'variance_pct'                => $evaluation->variancePct,
                'override_tier'               => $tier->value,
                'override_reason'             => $data->overrideReason,
                'override_approved_by'        => $data->managerApproval?->resolved_by,
                'override_approved_at'        => $data->managerApproval?->resolved_at,
                'override_approval_id'        => $data->managerApproval?->id,

                // Session / event metadata
                'presented_at'                => $p->presentedAt,
                'recorded_at'                 => now(),
                'bot_session_id'              => $p->botSessionId,
                'source_trigger'              => CashTransactionSource::CashierBot->value,

                // Group booking audit — null for standalone bookings
                'group_master_booking_id'     => $p->groupMasterBookingId,
                'is_group_payment'            => $p->isGroupPayment,
                'group_size_expected'         => $p->groupSizeExpected,
                'group_size_local'            => $p->groupSizeLocal,
            ]);

            // Phase 1 FX simplification dual-write — populate the new
            // simple-FX columns from primitives. Does NOT change cashier
            // behavior; tier system remains the source of truth until
            // Phase 2 flips the reader. UZS-paid only in Phase 1; other
            // currencies leave the columns NULL.
            $this->dualWriteSimpleFxFields($transaction, $data, $usdEquivalent);

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
     * Guard against duplicate cashier-bot payments.
     *
     * Must be called INSIDE a DB::transaction() after a lockForUpdate() on the
     * booking row so the check is race-safe under concurrent requests.
     *
     * Two tiers:
     *
     *  1. Standalone — any prior cashier_bot payment for this exact booking ID
     *     → DuplicatePaymentException
     *
     *  2. Group — any prior group payment sharing the same group_master_booking_id
     *     (catches sibling attempts even when a different booking ID was entered)
     *     → DuplicateGroupPaymentException
     */
    private function guardAgainstDuplicatePayment(PaymentPresentation $p): void
    {
        // Tier 1: standalone guard (applies to all bookings, grouped or not)
        $standaloneDuplicate = CashTransaction::where('beds24_booking_id', $p->beds24BookingId)
            ->where('source_trigger', CashTransactionSource::CashierBot->value)
            ->exists();

        if ($standaloneDuplicate) {
            throw new DuplicatePaymentException(
                "A cashier payment has already been recorded for booking #{$p->beds24BookingId}."
            );
        }

        // Tier 2: group sibling guard — catches the case where a different sibling
        // of the same group was already paid (different beds24_booking_id, same master)
        if ($p->isGroupPayment && $p->groupMasterBookingId !== null) {
            $groupDuplicate = CashTransaction::where('group_master_booking_id', $p->groupMasterBookingId)
                ->where('is_group_payment', true)
                ->where('source_trigger', CashTransactionSource::CashierBot->value)
                ->exists();

            if ($groupDuplicate) {
                throw new DuplicateGroupPaymentException(
                    "A group payment has already been recorded for group master booking #{$p->groupMasterBookingId}. " .
                    "Check the payment history before proceeding."
                );
            }
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

    // -------------------------------------------------------------------------
    // FX simplification — Phase 1 (dual-write helper + new entry point)
    //
    // See docs/architecture/fx-simplification-plan.md.
    // - dualWriteSimpleFxFields() runs INSIDE the existing recordPayment()
    //   transaction; populates the new 5 columns without touching tier logic.
    // - recordPaymentSimple() is the future entry point, NOT WIRED in Phase 1.
    //   No caller invokes it yet; Phase 2 will swap CashierBotController to
    //   call it instead of recordPayment(). Kept here so the canonical shape
    //   exists alongside the dual-write helper for tests.
    // -------------------------------------------------------------------------

    /**
     * Dual-write the new simple-FX columns onto a freshly-inserted
     * CashTransaction. Phase 1 covers UZS payments only; EUR/RUB-paid
     * transactions leave the columns NULL.
     *
     * Reference rate is fetched live from ExchangeRateService —
     * NOT pulled from PaymentPresentation, NOT pulled from the
     * BookingFxSync row. The audit ruled both of those out as risky
     * dependencies for a forward-write path.
     */
    private function dualWriteSimpleFxFields(
        \App\Models\CashTransaction $transaction,
        RecordPaymentData $data,
        ?float $usdEquivalentPaid,
    ): void {
        if (strtoupper($data->currencyPaid) !== 'UZS') {
            // Phase 1 scope. Phase 2 will widen.
            return;
        }

        $referenceRate = $this->resolveReferenceRateUzsPerUsd();

        $fields = \App\Services\Fx\SimpleFxFields::deriveForUzsPayment(
            amountPaidUzs: (float) $data->amountPaid,
            usdEquivalentPaid: $usdEquivalentPaid,
            referenceRateUzsPerUsd: $referenceRate,
            overrideReason: $data->overrideReason,
        );

        $transaction->update($fields->toArray());
    }

    /**
     * Phase 2 entry point — does NOT use PaymentPresentation, does
     * NOT use OverrideTier, does NOT throw ManagerApprovalRequired-
     * Exception. Single source of truth: FxThresholdGuard.
     *
     * UNUSED IN PHASE 1. Kept here so:
     *   1. tests can exercise it directly (regression bedding for Phase 2)
     *   2. the canonical shape lives alongside the legacy recordPayment
     *      so reviewers can compare side-by-side
     *
     * @throws \App\Exceptions\Fx\InvalidFxOverrideException
     */
    public function recordPaymentSimple(
        int     $shiftId,
        string  $beds24BookingId,
        float   $amountPaid,
        string  $currencyPaid,
        string  $paymentMethod,
        ?int    $cashierId,
        float   $referenceRate,
        float   $actualRate,
        ?string $overrideReason = null,
        ?string $guestName = null,
        ?string $roomNumber = null,
    ): \App\Models\CashTransaction {
        $guard = app(\App\Services\Fx\FxThresholdGuard::class);
        $deviationPct = $guard->deviationPct($referenceRate, $actualRate);
        $guard->validate($deviationPct, $overrideReason);

        // No PaymentPresentation, no OverrideTier, no ManagerApproval.
        // Just the row.
        return \App\Models\CashTransaction::create([
            'cashier_shift_id'   => $shiftId,
            'type'               => \App\Enums\TransactionType::IN->value,
            'amount'             => $amountPaid,
            'currency'           => strtoupper($currencyPaid),
            'category'           => \App\Enums\TransactionCategory::SALE->value,
            'beds24_booking_id'  => $beds24BookingId,
            'payment_method'     => $paymentMethod,
            'guest_name'         => $guestName,
            'room_number'        => $roomNumber,
            'reference'          => "Beds24 #{$beds24BookingId}",
            'created_by'         => $cashierId,
            'occurred_at'        => now(),
            'recorded_at'        => now(),
            'source_trigger'     => \App\Enums\CashTransactionSource::CashierBot->value,

            // The simple-FX columns are the whole point.
            'reference_rate'  => round($referenceRate, 4),
            'actual_rate'     => round($actualRate, 4),
            'deviation_pct'   => round($deviationPct, 4),
            'was_overridden'  => $guard->wasOverridden($deviationPct),
            'override_reason' => $overrideReason !== null && trim($overrideReason) !== '' ? trim($overrideReason) : null,
        ]);
    }

    /**
     * Resolve UZS-per-USD reference rate for the dual-write helper.
     * Returns null if every fallback fails — caller treats null as
     * "leave the columns NULL for this row" rather than crashing.
     */
    private function resolveReferenceRateUzsPerUsd(): ?float
    {
        try {
            $rates = app(\App\Services\ExchangeRateService::class)->getUsdToUzs();

            return $rates && isset($rates['rate']) ? (float) $rates['rate'] : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Phase1 dual-write: ExchangeRateService failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
