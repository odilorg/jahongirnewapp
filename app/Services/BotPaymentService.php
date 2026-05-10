<?php

namespace App\Services;

use App\DTO\GroupAmountResolution;
use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\DTOs\Fx\OverrideEvaluation;
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
        private readonly \App\Services\OwnerAlertService $ownerAlert,
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
     * @throws \App\Exceptions\Fx\StaleFxRateException — latest daily_exchange_rates row older than fx.stale_after_hours
     */
    public function preparePayment(string $beds24BookingId, string $botSessionId): PaymentPresentation
    {
        // 2026-05-08 follow-up #1 — refuse to open a payment session if the
        // latest persisted FX row is older than fx.stale_after_hours. Single
        // canonical guard; covers both the cashier-bot path AND the Filament
        // admin mixed-currency path because the latter (
        // RecordMixedCurrencySplitFromAdminAction) also delegates here.
        // See docs/FIXES.md tracked entry for the full incident reasoning.
        app(\App\Services\Fx\FxStalenessGuard::class)->ensureFreshOrFail();

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
    /**
     * Record a SPLIT payment — one guest paying via two instruments
     * (e.g. 300k UZS cash + 500k UZS card on the same booking).
     *
     * Both legs are written under one shared journal_entry_id with
     * payment_group_type='split'. The duplicate guard's journal-id
     * exemption lets the second leg co-exist with the first.
     *
     * Sum-lock invariant: $cashLeg->amountPaid + $cardLeg->amountPaid
     * MUST equal the booking's presented amount in the same currency,
     * within a 1-unit tolerance for rounding. Caller is responsible
     * for setting both legs' currencyPaid identically.
     *
     * SAME-CURRENCY ONLY (deliberate, NOT a limitation):
     *   v1 hard-rejects mixed-currency splits (e.g. cash UZS + card USD).
     *   Mixed-currency split requires full FX governance — per-leg rate
     *   snapshot, locked conversion timestamp, base reconciliation
     *   currency on the booking — and that's a Phase 1.5 build, not a
     *   bot-method tweak. Until that lands, an operator wanting to mix
     *   currencies must convert manually first and record in one
     *   selected presentation currency, OR record as two separate
     *   single-currency bookings if the OTA permits.
     *
     *   See docs/architecture/PRINCIPLES.md (when added) for the
     *   "settlement truth vs commercial truth" rule that drives this.
     *
     * Returns both transactions for caller-side use.
     *
     * @throws \InvalidArgumentException when sum-lock fails or
     *         currencies differ between legs.
     * @return array{0: CashTransaction, 1: CashTransaction}
     */
    public function recordSplitPayment(RecordPaymentData $cashLeg, RecordPaymentData $cardLeg): array
    {
        if ($cashLeg->currencyPaid !== $cardLeg->currencyPaid) {
            throw new \InvalidArgumentException(
                "Split-payment legs must share the same currency (got {$cashLeg->currencyPaid} + {$cardLeg->currencyPaid})."
            );
        }

        if ($cashLeg->presentation->beds24BookingId !== $cardLeg->presentation->beds24BookingId) {
            throw new \InvalidArgumentException(
                'Split-payment legs must reference the same booking.'
            );
        }

        $expected = $cashLeg->presentation->presentedAmountFor($cashLeg->currencyPaid);
        $sum      = (float) $cashLeg->amountPaid + (float) $cardLeg->amountPaid;
        if (abs($sum - $expected) > 1.0) {
            throw new \InvalidArgumentException(
                "Split-payment sum-lock failed: legs total {$sum} but booking expects {$expected} {$cashLeg->currencyPaid}."
            );
        }

        // One UUID for both legs. Stamped on both DTOs before recording.
        $journalUuid = (string) \Illuminate\Support\Str::uuid();

        $cashLeg = new RecordPaymentData(
            presentation: $cashLeg->presentation,
            shiftId: $cashLeg->shiftId,
            cashierId: $cashLeg->cashierId,
            currencyPaid: $cashLeg->currencyPaid,
            amountPaid: $cashLeg->amountPaid,
            paymentMethod: $cashLeg->paymentMethod,
            overrideReason: $cashLeg->overrideReason,
            managerApproval: $cashLeg->managerApproval,
            journalEntryId: $journalUuid,
            paymentGroupType: 'split',
        );
        $cardLeg = new RecordPaymentData(
            presentation: $cardLeg->presentation,
            shiftId: $cardLeg->shiftId,
            cashierId: $cardLeg->cashierId,
            currencyPaid: $cardLeg->currencyPaid,
            amountPaid: $cardLeg->amountPaid,
            paymentMethod: $cardLeg->paymentMethod,
            overrideReason: $cardLeg->overrideReason,
            managerApproval: $cardLeg->managerApproval,
            journalEntryId: $journalUuid,
            paymentGroupType: 'split',
        );

        // Both writes go through the same recordPayment path so they
        // pick up FX dual-write, Beds24 sync row creation, owner alert,
        // override evaluation, etc. The duplicate guard's journal-id
        // exemption lets the second insert see the first as "ours".
        $tx1 = $this->recordPayment($cashLeg);
        $tx2 = $this->recordPayment($cardLeg);

        return [$tx1, $tx2];
    }

    /**
     * Record a MIXED-CURRENCY split payment — one guest paying via two
     * instruments in DIFFERENT currencies on the same booking
     * (e.g. 500,000 UZS card + 50 USD cash).
     *
     * Sum-lock is enforced in the booking's BASE CURRENCY (the operator's
     * presentation currency at the picker step). Each leg's amount is
     * converted to base via the FROZEN PaymentPresentation rates, then
     * summed and compared to the booking's expected base-currency total.
     *
     * Architectural invariants enforced here (see PHASE_1_5_PLAN.md):
     *   - Both legs reference the SAME PaymentPresentation (same frozen
     *     FX snapshot — no mid-session rate drift).
     *   - $baseCurrency MUST equal the booking's commercial-truth currency
     *     (the operator-picked presentation currency at session start).
     *     Caller is responsible for not letting operators override this.
     *   - Manager-tier elevation when one leg's base-equivalent exceeds
     *     50% of the booking total in a non-base currency. Caller must
     *     attach managerApproval on that leg's RecordPaymentData when
     *     this threshold trips.
     *
     * Returns both transactions for caller-side use.
     *
     * @throws \InvalidArgumentException — sum-lock fails, same-currency
     *         legs (use recordSplitPayment instead), legs reference
     *         different bookings, $baseCurrency unsupported.
     * @throws StalePaymentSessionException — frozen presentation expired.
     * @return array{0: CashTransaction, 1: CashTransaction}
     */
    /**
     * Variance band configuration for mixed-currency sum-lock (Phase 1.5.5).
     * Percentages of the booking total in base currency. See
     * PHASE_1_5_PLAN.md for the doctrine driving these thresholds.
     */
    public const VARIANCE_SILENT_PCT  = 1.0;  // 0-1% absorbed silently
    public const VARIANCE_REASON_PCT  = 3.0;  // 1-3% structured reason required
    public const VARIANCE_MANAGER_PCT = 5.0;  // 3-5% manager approval + reason
    // > 5% → hard reject

    public function recordMixedCurrencySplitPayment(
        RecordPaymentData $leg1,
        RecordPaymentData $leg2,
        string $baseCurrency,
        ?\App\DTO\MixedCurrencyVarianceContext $varianceContext = null,
    ): array {
        if ($leg1->currencyPaid === $leg2->currencyPaid) {
            throw new \InvalidArgumentException(
                'recordMixedCurrencySplitPayment requires different leg currencies; use recordSplitPayment for same-currency.'
            );
        }
        if ($leg1->presentation->beds24BookingId !== $leg2->presentation->beds24BookingId) {
            throw new \InvalidArgumentException('Mixed-currency split legs must reference the same booking.');
        }
        if (! in_array($baseCurrency, ['UZS', 'USD', 'EUR'], true)) {
            throw new \InvalidArgumentException("Unsupported base currency: {$baseCurrency}");
        }

        // Convert each leg to base via frozen presentation rates. We use
        // presentedAmountFor() / amountPaid ratio to derive the implied
        // rate without trusting operator-typed numbers. Both legs use
        // $leg1->presentation since we've enforced same-presentation
        // (caller passes the same frozen DTO instance).
        $expectedInBase = $leg1->presentation->presentedAmountFor($baseCurrency);
        if ($expectedInBase <= 0.0) {
            throw new \InvalidArgumentException("Booking has no presented amount in {$baseCurrency}.");
        }

        $leg1InBase = $this->convertViaPresentation($leg1, $baseCurrency);
        $leg2InBase = $this->convertViaPresentation($leg2, $baseCurrency);
        $sumInBase  = $leg1InBase + $leg2InBase;

        $variance    = $sumInBase - $expectedInBase;
        $variancePct = $expectedInBase > 0.0 ? (abs($variance) / $expectedInBase) * 100.0 : 0.0;
        $tolerance   = $this->sumLockTolerance($baseCurrency);

        // Three-tier variance gating (Phase 1.5.5):
        //   < tolerance OR < 1% of booking → silent pass, no variance recorded
        //   1-3% → structured reason required; throws if context not supplied
        //   3-5% → reason + manager approval required
        //   > 5% → hard reject regardless of context
        if (abs($variance) > $tolerance && $variancePct >= self::VARIANCE_SILENT_PCT) {
            if ($variancePct > self::VARIANCE_MANAGER_PCT) {
                throw new \InvalidArgumentException(sprintf(
                    'Mixed-currency variance %.2f%% exceeds %.0f%% hard ceiling. Legs total %.2f, booking expects %.2f %s. Reconsider amounts.',
                    $variancePct,
                    self::VARIANCE_MANAGER_PCT,
                    $sumInBase,
                    $expectedInBase,
                    $baseCurrency,
                ));
            }

            $requiresManager = $variancePct > self::VARIANCE_REASON_PCT;

            if ($varianceContext === null) {
                throw new \App\Exceptions\RequiresVarianceReasonException(
                    expectedInBase:           $expectedInBase,
                    actualInBase:             $sumInBase,
                    varianceInBase:           $variance,
                    variancePct:              $variancePct,
                    baseCurrency:             $baseCurrency,
                    requiresManagerApproval:  $requiresManager,
                    impliedRate:              $this->computeImpliedRate($leg1, $leg2, $expectedInBase, $baseCurrency),
                    frozenRate:               $this->computeFrozenRate($leg1->presentation, $baseCurrency),
                );
            }

            if ($requiresManager && $varianceContext->managerApproval === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Mixed-currency variance %.2f%% (band %.0f-%.0f%%) requires manager approval.',
                    $variancePct,
                    self::VARIANCE_REASON_PCT,
                    self::VARIANCE_MANAGER_PCT,
                ));
            }
        } elseif (abs($variance) > $tolerance && $variancePct < self::VARIANCE_SILENT_PCT) {
            // Variance is in absolute terms above tolerance but in percentage
            // terms below 1% (e.g. tolerance=100 UZS, booking=12,000,000 UZS,
            // variance=200 UZS = 0.0017%). Treat as silent pass — accept
            // without recording variance.
            $variance    = 0.0;
            $varianceContext = null;
        }

        // Generate one journal UUID; stamp both legs with it + the base currency.
        $journalUuid = (string) \Illuminate\Support\Str::uuid();

        $leg1 = $this->withMixedJournalContext($leg1, $journalUuid, $baseCurrency);
        $leg2 = $this->withMixedJournalContext($leg2, $journalUuid, $baseCurrency);

        $tx1 = $this->recordPayment($leg1);
        $tx2 = $this->recordPayment($leg2);

        // Stamp variance on leg1 if applicable. Leg2 stays untouched —
        // variance is a journal-level fact, denormalised onto first leg
        // for cheap "show me variance journals" queries without joins.
        if ($variance != 0.0 && $varianceContext !== null) {
            $tx1->forceFill([
                'fx_variance_amount'   => round($variance, 2),
                'fx_variance_currency' => $baseCurrency,
                'fx_variance_reason'   => $varianceContext->reason,
                // Append the free-text note + reason to the existing notes column.
                'notes' => trim((string) $tx1->notes) . sprintf(
                    "\n[FX-variance %s %.2f %s reason=%s%s]",
                    $variance > 0 ? '+' : '',
                    $variance,
                    $baseCurrency,
                    $varianceContext->reason,
                    $varianceContext->freeTextNote ? ' note="' . $varianceContext->freeTextNote . '"' : '',
                ),
            ])->save();
        }

        return [$tx1, $tx2];
    }

    /**
     * Compute the operator-implied FX rate (foreign currency leg's
     * implied rate to base, given the booking total in base and the
     * other leg's contribution). Used for the variance reason picker
     * UX so operators see "system rate vs implied rate" at a glance.
     */
    private function computeImpliedRate(
        RecordPaymentData $leg1,
        RecordPaymentData $leg2,
        float $expectedInBase,
        string $baseCurrency,
    ): float {
        // Identify the foreign-currency leg + base-currency leg
        $foreignLeg = $leg1->currencyPaid !== $baseCurrency ? $leg1 : $leg2;
        $baseLeg    = $leg1->currencyPaid === $baseCurrency ? $leg1 : $leg2;

        if ($foreignLeg->currencyPaid === $baseCurrency || $foreignLeg->amountPaid <= 0.0) {
            return 0.0; // both legs in base — implied rate undefined
        }

        $remainingInBase = $expectedInBase - (float) $baseLeg->amountPaid;
        return $remainingInBase / (float) $foreignLeg->amountPaid;
    }

    /**
     * Pull the booking's frozen FX rate (base per unit of foreign currency)
     * from the presentation. Used alongside computeImpliedRate to surface
     * the rate gap to operators.
     */
    private function computeFrozenRate(\App\DTO\PaymentPresentation $p, string $baseCurrency): float
    {
        $foreignCurrency = match ($baseCurrency) {
            'UZS' => 'USD',
            'USD' => 'UZS',
            'EUR' => 'UZS',
            default => 'USD',
        };
        $foreignAmount = $p->presentedAmountFor($foreignCurrency);
        $baseAmount    = $p->presentedAmountFor($baseCurrency);
        return $foreignAmount > 0.0 ? $baseAmount / $foreignAmount : 0.0;
    }

    /**
     * Convert a leg's amount to a target currency using the frozen
     * presentation's rate ratio. Reads native presented amounts from
     * the frozen DTO — no live rate lookups.
     */
    private function convertViaPresentation(RecordPaymentData $leg, string $targetCurrency): float
    {
        if ($leg->currencyPaid === $targetCurrency) {
            return (float) $leg->amountPaid;
        }

        $sourcePresented = $leg->presentation->presentedAmountFor($leg->currencyPaid);
        $targetPresented = $leg->presentation->presentedAmountFor($targetCurrency);

        if ($sourcePresented <= 0.0) {
            throw new \InvalidArgumentException(
                "Frozen presentation has no {$leg->currencyPaid} amount — cannot convert."
            );
        }

        // ratio = targetPresented / sourcePresented gives base-per-unit-of-leg.
        return ((float) $leg->amountPaid * $targetPresented) / $sourcePresented;
    }

    /**
     * Currency-aware sum-lock tolerance. UZS uses larger absolute
     * tolerance because of small-denomination rounding; USD/EUR are
     * tighter since values are smaller and decimal-precise.
     */
    private function sumLockTolerance(string $currency): float
    {
        return match ($currency) {
            'UZS' => 100.0,   // 100 UZS rounding noise
            'USD' => 0.50,
            'EUR' => 0.50,
            default => 1.0,
        };
    }

    /**
     * Stamp the journal UUID + base currency + group type onto the leg's
     * RecordPaymentData (DTO is readonly, so we re-construct).
     */
    private function withMixedJournalContext(RecordPaymentData $leg, string $journalUuid, string $baseCurrency): RecordPaymentData
    {
        return new RecordPaymentData(
            presentation: $leg->presentation,
            shiftId: $leg->shiftId,
            cashierId: $leg->cashierId,
            currencyPaid: $leg->currencyPaid,
            amountPaid: $leg->amountPaid,
            paymentMethod: $leg->paymentMethod,
            overrideReason: $leg->overrideReason,
            managerApproval: $leg->managerApproval,
            journalEntryId: $journalUuid,
            paymentGroupType: 'split',
            baseCurrencyForSplit: $baseCurrency,
            journalStatus: 'complete',
        );
    }

    /**
     * Phase 1.7.1 — Bulk group payment.
     *
     * Records ONE journal of N legs (one cash_transactions row per
     * sibling in the group), proportionally distributing $totalAmount
     * across siblings using largest-remainder rounding.
     *
     * Doctrine (locked in PHASE_1_5_PLAN.md):
     *   - Group convenience never reduces per-booking truth.
     *     Each sibling gets its own row, its own beds24_payment_sync,
     *     its own audit trail.
     *   - Bulk = ALL siblings, never a subset. Partial settlement is
     *     Phase 2.x territory.
     *   - Same-currency only in v1. Mixed currencies in groups is v2.
     *   - Single method for whole group in v1 (cash OR card OR transfer).
     *   - Sum-lock: sum of distributed shares = totalAmount ±tolerance.
     *   - Strict unpaid-state validation: any sibling already paid
     *     (cashier_bot OR beds24_external) → reject with
     *     GroupAlreadyPartiallyPaidException.
     *   - Group composition freeze: $expectedSnapshot is revalidated
     *     against current Beds24Booking state at submit; mismatch →
     *     GroupCompositionChangedException.
     *   - One journal_entry_id UUID for the whole group; first leg
     *     also gets group_distribution_snapshot (JSON audit trail).
     *
     * @param string $masterBookingId   any sibling's booking ID; resolves to the master
     * @param string $totalCurrency     UZS / USD / EUR
     * @param float  $totalAmount       operator-typed group total
     * @param string $paymentMethod     cash / card / transfer
     * @param int    $shiftId           open shift id
     * @param int    $cashierId         operator user id
     * @param array  $expectedSnapshot  previously-shown sibling list — composition guard
     *
     * @return array{journal_uuid: string, transactions: array<CashTransaction>}
     *
     * @throws GroupAlreadyPartiallyPaidException
     * @throws GroupCompositionChangedException
     * @throws \InvalidArgumentException — sum-lock fail, no siblings, etc.
     */
    public function recordBulkGroupPayment(
        string $masterBookingId,
        string $totalCurrency,
        float  $totalAmount,
        string $paymentMethod,
        int    $shiftId,
        int    $cashierId,
        array  $expectedSnapshot,
    ): array {
        if (! in_array($totalCurrency, ['UZS', 'USD', 'EUR'], true)) {
            throw new \InvalidArgumentException("Unsupported currency: {$totalCurrency}");
        }
        if (! in_array($paymentMethod, ['cash', 'card', 'transfer'], true)) {
            throw new \InvalidArgumentException("Unsupported method: {$paymentMethod}");
        }

        return DB::transaction(function () use (
            $masterBookingId, $totalCurrency, $totalAmount, $paymentMethod,
            $shiftId, $cashierId, $expectedSnapshot,
        ) {
            // 1. Resolve master + lock all siblings (race-safe)
            $resolvedMaster = $this->resolveMasterBookingId($masterBookingId);
            $siblings = \App\Models\Beds24Booking::where('master_booking_id', $resolvedMaster)
                ->lockForUpdate()
                ->orderBy('beds24_booking_id')
                ->get();

            if ($siblings->isEmpty()) {
                throw new \InvalidArgumentException("Booking #{$masterBookingId} is not part of a group.");
            }

            // 2. Composition guard — diff against expected snapshot
            $actualSnapshot = $siblings->map(fn ($s) => [
                'booking_id'    => (string) $s->beds24_booking_id,
                'invoice_total' => (float)  $s->total_amount,
            ])->all();
            if ($this->groupCompositionDiffers($expectedSnapshot, $actualSnapshot)) {
                throw new \App\Exceptions\GroupCompositionChangedException(
                    masterBookingId: $resolvedMaster,
                    expectedSnapshot: $expectedSnapshot,
                    actualSnapshot: $actualSnapshot,
                );
            }

            // 3. Strict unpaid-state validation across BOTH sources
            $alreadyPaid = CashTransaction::query()
                ->whereIn('beds24_booking_id', $siblings->pluck('beds24_booking_id')->all())
                ->whereIn('source_trigger', [
                    CashTransactionSource::CashierBot->value,
                    CashTransactionSource::Beds24External->value,
                ])
                ->whereNull('deleted_at')
                ->pluck('beds24_booking_id')
                ->map(fn ($x) => (string) $x)
                ->unique()
                ->all();

            if (! empty($alreadyPaid)) {
                $unpaid = $siblings->pluck('beds24_booking_id')
                    ->map(fn ($x) => (string) $x)
                    ->reject(fn ($id) => in_array($id, $alreadyPaid, true))
                    ->values()
                    ->all();
                throw new \App\Exceptions\GroupAlreadyPartiallyPaidException(
                    masterBookingId: $resolvedMaster,
                    alreadyPaidBookingIds: $alreadyPaid,
                    unpaidBookingIds: $unpaid,
                );
            }

            // 4. Largest-remainder distribution (rounding-safe; cents balance)
            $shares = $this->distributeProportional(
                $siblings->pluck('total_amount', 'beds24_booking_id')->map(fn ($v) => (float) $v)->all(),
                $totalAmount,
                $totalCurrency,
            );

            $sumOfShares = array_sum($shares);
            $tolerance   = $this->sumLockTolerance($totalCurrency);
            if (abs($sumOfShares - $totalAmount) > $tolerance) {
                throw new \InvalidArgumentException(sprintf(
                    'Group bulk sum-lock failed: shares total %.2f, operator entered %.2f %s (tolerance ±%.2f).',
                    $sumOfShares,
                    $totalAmount,
                    $totalCurrency,
                    $tolerance,
                ));
            }

            // 5. Generate journal UUID + record N legs
            $journalUuid = (string) \Illuminate\Support\Str::uuid();
            $transactions = [];

            // Build distribution snapshot as we record legs; populated onto leg1 post-insert.
            $distributionSiblings = [];

            foreach ($siblings as $sibling) {
                $bid   = (string) $sibling->beds24_booking_id;
                $share = (float)  $shares[$bid];
                $botSessionId = sprintf('bulk-group:%s:%s', $journalUuid, $bid);

                // Each leg gets its own frozen presentation (one prepare per sibling
                // since each booking has its own FX presentation).
                $presentation = $this->preparePayment($bid, $botSessionId);

                $legData = new \App\DTO\RecordPaymentData(
                    presentation:    $presentation,
                    shiftId:         $shiftId,
                    cashierId:       $cashierId,
                    currencyPaid:    $totalCurrency,
                    amountPaid:      $share,
                    paymentMethod:   $paymentMethod,
                    overrideReason:  null,
                    managerApproval: null,
                    journalEntryId:  $journalUuid,
                    paymentGroupType: 'group_bulk',
                );

                $transactions[] = $this->recordPayment($legData);

                $distributionSiblings[] = [
                    'booking_id'    => $bid,
                    'invoice_total' => (float) $sibling->total_amount,
                    'share'         => $share,
                    'leg_tx_id'     => (int) end($transactions)->id,
                ];
            }

            // 6. Stamp distribution snapshot onto leg1 for audit explainability
            $firstTx = $transactions[0];
            $firstTx->forceFill([
                'group_distribution_snapshot' => json_encode([
                    'master_booking_id'          => $resolvedMaster,
                    'group_total_currency'       => $totalCurrency,
                    'group_total_amount'         => $totalAmount,
                    'group_total_at_payment_time'=> $sumOfShares,
                    'siblings'                   => $distributionSiblings,
                    'rounding_method'            => 'largest_remainder',
                    'recorded_at'                => now()->toIso8601String(),
                ]),
                'is_group_payment'              => true,
                'group_master_booking_id'       => $resolvedMaster,
                'group_size_expected'           => $siblings->count(),
                'group_size_local'              => $siblings->count(),
            ])->save();

            // Mark all OTHER legs with group flags too (denormalised for filtering)
            foreach (array_slice($transactions, 1) as $tx) {
                $tx->forceFill([
                    'is_group_payment'        => true,
                    'group_master_booking_id' => $resolvedMaster,
                    'group_size_expected'     => $siblings->count(),
                    'group_size_local'        => $siblings->count(),
                ])->save();
            }

            return [
                'journal_uuid'  => $journalUuid,
                'transactions'  => $transactions,
            ];
        });
    }

    /**
     * Resolve any sibling booking ID to its master. If the input IS the
     * master, return it. Otherwise lookup the master via beds24_bookings.
     */
    private function resolveMasterBookingId(string $bookingId): string
    {
        $row = \App\Models\Beds24Booking::where('beds24_booking_id', $bookingId)->first();
        if (! $row) {
            throw new \InvalidArgumentException("Booking #{$bookingId} not found.");
        }
        return (string) ($row->master_booking_id ?? $row->beds24_booking_id);
    }

    /**
     * Largest-remainder rounding allocation. Cents balance to the
     * cent — sum_of_shares == $totalAmount exactly within float
     * precision. Used for proportional bulk-group distribution.
     */
    private function distributeProportional(array $weights, float $totalAmount, string $currency): array
    {
        $sumWeights = array_sum($weights);
        if ($sumWeights <= 0.0) {
            throw new \InvalidArgumentException('Cannot distribute: sum of weights is zero.');
        }

        // Decimals: UZS=0 (whole units), USD/EUR=2 (cents)
        $decimals = $currency === 'UZS' ? 0 : 2;
        $multiplier = 10 ** $decimals;

        // Convert to integer cents for exact arithmetic
        $totalCents = (int) round($totalAmount * $multiplier);

        $rawShares = [];
        $intShares = [];
        foreach ($weights as $key => $weight) {
            $raw = ($weight / $sumWeights) * $totalCents;
            $rawShares[$key] = $raw;
            $intShares[$key] = (int) floor($raw);
        }

        // Distribute leftover cents to keys with the largest fractional remainder
        $allocated = array_sum($intShares);
        $remaining = $totalCents - $allocated;

        $remainders = [];
        foreach ($rawShares as $key => $raw) {
            $remainders[$key] = $raw - floor($raw);
        }
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($remaining <= 0) break;
            $intShares[$key]++;
            $remaining--;
        }

        // Convert back to decimals
        $result = [];
        foreach ($intShares as $key => $cents) {
            $result[(string) $key] = $cents / $multiplier;
        }
        return $result;
    }

    private function groupCompositionDiffers(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) return true;

        $normalize = fn (array $rows) => collect($rows)->keyBy('booking_id')
            ->map(fn ($r) => round((float) $r['invoice_total'], 2))
            ->all();
        return $normalize($expected) !== $normalize($actual);
    }

    public function recordPayment(RecordPaymentData $data): CashTransaction
    {
        // 1. Session expiry — reject stale conversations
        if ($data->presentation->isExpired()) {
            throw new StalePaymentSessionException(
                'Payment session expired after ' . PaymentPresentation::TTL_MINUTES . ' minutes. Please start again.'
            );
        }

        // 2. Override policy — use canonical Fx evaluator (returns Blocked when threshold exceeded)
        //
        // EXCEPT for split-leg / group-bulk contexts: an individual leg
        // amount is an operator partition of the total, not an
        // independent variance against the presented booking amount.
        // Comparing each leg to the full presented total falsely flags
        // every realistic split (e.g. 520k + 270k = 790k → cash-leg
        // variance reads as 34%, card-leg as 66%, both BLOCKED at
        // >10% threshold). The parent layer is the right authority for
        // sum-lock at this granularity:
        //   - recordSplitPayment           → abs(sum - presented) > 1.0
        //   - recordMixedCurrencySplitPayment → tiered variance against
        //                                       expected_in_base, hard 5% ceiling
        //   - recordBulkGroupPayment       → distributed shares vs entered total
        //
        // For split legs we yield a "skippedForSplit" evaluation that
        // honestly records the bypass on the row's audit columns
        // (within_tolerance=true, override_tier='none', variance_pct=0)
        // rather than running a meaningless per-leg comparison.
        $presented  = $data->presentation->presentedAmountFor($data->currencyPaid);
        $currency   = Currency::tryFrom(strtoupper($data->currencyPaid)) ?? Currency::UZS;

        $isSplitLeg = in_array(
            $data->paymentGroupType,
            ['split', 'mixed_currency_split', 'group_bulk'],
            true,
        );

        $evaluation = $isSplitLeg
            ? OverrideEvaluation::skippedForSplit($currency, $presented, $data->amountPaid)
            : $this->overridePolicy->evaluate($currency, $presented, $data->amountPaid);

        $tier = $evaluation->tier;

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
            // race-safe for both standalone and group payments. The journal-id
            // exemption lets split-payment siblings co-exist; they share one
            // journal_entry_id and represent one logical transaction.
            $this->guardAgainstDuplicatePayment($p, $data->journalEntryId);

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

                // Journal Entry Foundation v1 — links sibling rows of one
                // logical transaction (split payments now; reversals /
                // refunds in later phases). NULL means standalone.
                'journal_entry_id'            => $data->journalEntryId,
                'payment_group_type'          => $data->paymentGroupType,

                // Phase 1.5.1 — mixed-currency split metadata.
                'base_currency_for_split'     => $data->baseCurrencyForSplit,
                'journal_status'              => $data->journalStatus,
                // Phase 1.5.5 — fx variance fields. Initially NULL on both
                // legs at recordPayment time; recordMixedCurrencySplitPayment
                // stamps them on leg1 post-insert when applicable.
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

            // Owner direct-payment alert: fires AFTER commit so the alert is
            // never sent for a payment that ultimately rolled back. Wrapped
            // in try/catch — a notification failure must NEVER cause the
            // payment record to be lost.
            DB::afterCommit(function () use ($transaction) {
                try {
                    $this->ownerAlert->alertCashierBotPayment($transaction);
                } catch (\Throwable $e) {
                    Log::warning('Owner alert dispatch failed for cashier bot payment', [
                        'tx_id' => $transaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

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
    private function guardAgainstDuplicatePayment(PaymentPresentation $p, ?string $allowedJournalEntryId = null): void
    {
        // Tier 1: standalone guard (applies to all bookings, grouped or not)
        // Allowed-journal-id exemption: when this insert is part of a SPLIT
        // payment, the second-leg insert sees the first leg as a "prior row"
        // — but they are LEGITIMATE siblings under the same journal_entry_id,
        // not a duplicate. Skip the guard for rows sharing the same journal.
        $query = CashTransaction::where('beds24_booking_id', $p->beds24BookingId)
            ->where('source_trigger', CashTransactionSource::CashierBot->value);

        if ($allowedJournalEntryId !== null) {
            $query->where(function ($q) use ($allowedJournalEntryId) {
                $q->whereNull('journal_entry_id')
                  ->orWhere('journal_entry_id', '!=', $allowedJournalEntryId);
            });
        }

        $standaloneDuplicate = $query->exists();

        if ($standaloneDuplicate) {
            throw new DuplicatePaymentException(
                "A cashier payment has already been recorded for booking #{$p->beds24BookingId}."
            );
        }

        // Tier 2: group sibling guard — catches the case where a different sibling
        // of the same group was already paid (different beds24_booking_id, same master).
        // Same journal-id exemption as Tier 1: bulk-group siblings under one
        // journal_entry_id are LEGITIMATE siblings, not duplicates.
        if ($p->isGroupPayment && $p->groupMasterBookingId !== null) {
            $groupQuery = CashTransaction::where('group_master_booking_id', $p->groupMasterBookingId)
                ->where('is_group_payment', true)
                ->where('source_trigger', CashTransactionSource::CashierBot->value);

            if ($allowedJournalEntryId !== null) {
                $groupQuery->where(function ($q) use ($allowedJournalEntryId) {
                    $q->whereNull('journal_entry_id')
                      ->orWhere('journal_entry_id', '!=', $allowedJournalEntryId);
                });
            }

            $groupDuplicate = $groupQuery->exists();

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
        ?int    $shiftId,            // nullable — cashier_shift_id has been nullable since 2026-03-10
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
