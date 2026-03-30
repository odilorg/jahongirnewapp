<?php

namespace App\Services;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\OverrideTier;
use App\Exceptions\BookingNotPayableException;
use App\Exceptions\ManagerApprovalRequiredException;
use App\Exceptions\PaymentBlockedException;
use App\Exceptions\StalePaymentSessionException;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cashier bot payment flow — the only service the bot should call for FX payments.
 *
 * Core rule: this service never calculates exchange rates. It reads from
 * booking_fx_syncs (via FxSyncService) and records against a frozen snapshot.
 *
 * Two public methods:
 *
 *   preparePayment()  — resolves booking, ensures fresh FX sync, returns frozen DTO.
 *                       Bot stores DTO in session; never re-fetches mid-conversation.
 *
 *   recordPayment()   — validates frozen DTO, checks override tier, writes cash_transaction.
 *                       Never re-reads live booking_fx_syncs row.
 */
class BotPaymentService
{
    public function __construct(
        private readonly FxSyncService           $fxSync,
        private readonly OverridePolicyEvaluator  $overridePolicy,
        private readonly FxManagerApprovalService $approvalService,
    ) {}

    // -------------------------------------------------------------------------
    // Step 1: Prepare
    // -------------------------------------------------------------------------

    /**
     * Resolve booking by Beds24 booking ID, ensure FX sync is fresh, return frozen DTO.
     *
     * $botSessionId should uniquely identify this Telegram conversation, e.g.:
     *   "{$chatId}:{$messageId}" or a UUID stored in TelegramPosSession.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \App\Exceptions\Beds24RateLimitException
     */
    public function preparePayment(string $beds24BookingId, string $botSessionId): PaymentPresentation
    {
        // IMPORTANT: query by beds24_booking_id (external Beds24 ID), NOT by local model PK (id)
        $booking = Beds24Booking::where('beds24_booking_id', $beds24BookingId)->firstOrFail();

        $sync = $this->fxSync->ensureFresh($booking, 'bot');

        return PaymentPresentation::fromSync($booking, $sync, $botSessionId);
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
     *  3. Booking still payable (not cancelled mid-conversation)
     *  4. Manager approval still 'approved' (lockForUpdate, then mark consumed)
     *  5. Record CashTransaction atomically
     *
     * @throws StalePaymentSessionException
     * @throws PaymentBlockedException
     * @throws ManagerApprovalRequiredException
     * @throws BookingNotPayableException
     */
    public function recordPayment(RecordPaymentData $data): CashTransaction
    {
        // 1. Session expiry — reject stale conversations
        if ($data->presentation->isExpired()) {
            throw new StalePaymentSessionException(
                'Payment session expired after ' . PaymentPresentation::TTL_MINUTES . ' minutes. Please start again.'
            );
        }

        // 2. Override policy
        $presented = $data->presentation->presentedAmountFor($data->currencyPaid);
        $tier = $this->overridePolicy->evaluate($presented, $data->amountPaid);

        if ($tier === OverrideTier::Blocked) {
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

        return DB::transaction(function () use ($data, $tier, $presented): CashTransaction {
            $p = $data->presentation; // frozen — never re-reads live sync

            // 3. Booking still payable (check inside transaction)
            // Query by beds24_booking_id, NOT by local PK
            $booking = Beds24Booking::where('beds24_booking_id', $p->beds24BookingId)->first();
            if (! $booking || ! $booking->isPayable()) {
                throw new BookingNotPayableException(
                    "Booking #{$p->beds24BookingId} is no longer in a payable state."
                );
            }

            // 4. Consume manager approval atomically (lockForUpdate inside consume())
            if ($data->managerApproval) {
                // consume() re-locks and verifies status is still 'approved' before marking consumed
                // placeholder — we link after creating transaction below
            }

            // 5. Create cash transaction
            $transaction = CashTransaction::create([
                // Core transaction fields (existing columns)
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

                // FX presentation audit columns (new)
                'booking_fx_sync_id'          => $p->syncId,
                'daily_exchange_rate_id'      => $p->dailyExchangeRateId,
                'amount_presented_uzs'        => $p->uzsPresented,
                'amount_presented_eur'        => $p->eurPresented,
                'amount_presented_rub'        => $p->rubPresented,
                'presented_currency'          => $data->currencyPaid,
                'amount_presented_selected'   => $presented,  // snapshot for selected currency
                'is_override'                 => $tier !== OverrideTier::None,
                'override_tier'               => $tier->value,
                'override_reason'             => $data->overrideReason,
                'override_approved_by'        => $data->managerApproval?->resolved_by,
                'override_approved_at'        => $data->managerApproval?->resolved_at,
                'presented_at'                => $p->presentedAt,
                'bot_session_id'              => $p->botSessionId,
                'source_trigger'              => 'bot',
            ]);

            // Consume approval now that we have the transaction ID
            if ($data->managerApproval) {
                $this->approvalService->consume($data->managerApproval, $transaction->id);
            }

            return $transaction;
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
     * Isolated query — add ->where('status', 'effective') here when reversals are introduced.
     */
    private function effectiveCollected(string $beds24BookingId, string $currency): float
    {
        return (float) CashTransaction::where('beds24_booking_id', $beds24BookingId)
            ->where('currency', $currency)
            // ->where('status', 'effective')  ← add when reversals introduced
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

        if ($tier !== OverrideTier::None) {
            $notes .= "\n⚠ Переопределение ({$tier->value}): {$data->overrideReason}";
        }

        return $notes;
    }
}
