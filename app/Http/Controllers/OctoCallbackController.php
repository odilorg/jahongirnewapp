<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use App\Models\OctoPaymentAttempt;
use App\Services\BookingInquiryNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OctoCallbackController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Octo callback data:', $request->all());

        // Phase S — signature guard (presence always enforced; crypto flag-gated).
        if ($guard = $this->guardSignature($request)) {
            return $guard;
        }

        $transactionId = (string) $request->input('shop_transaction_id', '');
        $status        = (string) $request->input('status', '');
        $paidSum       = $request->input('total_sum');

        // Route by prefix: two parallel pipelines share the same callback.
        //   booking_{id}_{rnd}  → legacy Booking pipeline (unchanged)
        //   inquiry_{id}_{rnd}  → new BookingInquiry pipeline
        if (str_starts_with($transactionId, 'inquiry_')) {
            return $this->handleInquiryCallback($transactionId, $status, $paidSum);
        }

        if (str_starts_with($transactionId, 'booking_')) {
            return $this->handleBookingCallback($transactionId, $status, $paidSum);
        }

        Log::warning('Octo callback: unrecognised transaction prefix', [
            'transaction_id' => $transactionId,
        ]);

        return response()->json(['error' => 'Unrecognised transaction prefix'], 400);
    }

    /**
     * Legacy Booking path — unchanged from the original implementation.
     */
    private function handleBookingCallback(string $transactionId, string $status, $paidSum)
    {
        $bookingId = null;
        if (preg_match('/^booking_(\d+)_/i', $transactionId, $matches)) {
            $bookingId = $matches[1] ?? null;
        }

        if (! $bookingId) {
            return response()->json(['error' => 'Could not extract booking ID from transaction_id'], 400);
        }

        $booking = Booking::find($bookingId);
        if (! $booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        if ($status === 'success') {
            $booking->payment_status = 'paid';
            $booking->save();

            GuestPayment::create([
                'guest_id'       => $booking->guest_id,
                'booking_id'     => $booking->id,
                'amount'         => $paidSum,
                'payment_date'   => now(),
                'payment_method' => 'card',
                'payment_status' => 'paid',
            ]);

            Log::info("Booking #{$booking->id} marked paid, sum={$paidSum}.");
        } else {
            $booking->payment_status = 'failed';
            $booking->save();

            Log::warning("Booking #{$booking->id} payment failed or canceled.");
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * New BookingInquiry payment path.
     *
     * Phase 2 lookup order:
     *  1. OctoPaymentAttempt by transaction_id (attempt-first, for all links
     *     generated via GeneratePaymentLinkAction since Phase 1).
     *  2. BookingInquiry.octo_transaction_id fallback for pre-Phase-1 links
     *     that have no attempt row — logged so we can remove this path in
     *     Phase 5 once all legacy links have expired.
     *
     * After inquiry processing the matched attempt's status is stamped
     * (paid/failed) in a separate try/catch that never withholds the 200.
     *
     * Safety guards (in order):
     *  1. Idempotency: if paid_at is already set, this is a duplicate
     *     webhook retry. Return 200 immediately without re-updating or
     *     re-notifying. Octo retries unacknowledged callbacks; without
     *     this guard we would double-stamp paid_at and fire a second
     *     "💰 Payment received" Telegram message.
     *  2. Terminal-status guard: if the inquiry is already cancelled or
     *     spam, a successful payment is UNEXPECTED and needs a human.
     *     We store the payment fields so nothing is lost, but we do NOT
     *     silently revive the status — we fire a red-flag notification
     *     so ops can investigate.
     *
     * On the happy path:
     *   success → mark confirmed, stamp paid_at, fire Telegram notification.
     *
     * On payment failure:
     *   Keep inquiry in awaiting_payment so operator can resend a link or
     *   follow up. We do NOT auto-cancel — a failed first attempt often
     *   becomes a successful retry.
     */
    private function handleInquiryCallback(string $transactionId, string $status, $paidSum)
    {
        // Phase 2 — attempt-first lookup.
        $attempt = OctoPaymentAttempt::where('transaction_id', $transactionId)->first();
        $inquiry = null;

        // Phase 4 — terminal-attempt guard.
        // If the attempt row is already in a terminal state, return 200 immediately
        // without touching the inquiry. Three cases:
        //   superseded — operator regenerated the link; a newer attempt covers this
        //                inquiry. The old link may still fire because Octo keeps it
        //                live until its own TTL expires. Silently ignore.
        //   paid       — duplicate webhook retry. 200 so Octo stops retrying.
        //   failed     — second failure callback. 200 so Octo stops retrying.
        // Returning a non-200 here would cause Octo to keep retrying the callback,
        // potentially triggering unintended logic on a later attempt.
        if ($attempt && in_array($attempt->status, [
            OctoPaymentAttempt::STATUS_PAID,
            OctoPaymentAttempt::STATUS_FAILED,
            OctoPaymentAttempt::STATUS_SUPERSEDED,
        ], true)) {
            Log::info('Octo callback ignored: attempt already in terminal state', [
                'attempt_id'     => $attempt->id,
                'attempt_status' => $attempt->status,
                'transaction_id' => $transactionId,
                'inquiry_id'     => $attempt->inquiry_id,
                'octo_status'    => $status,
            ]);

            return response()->json(['status' => 'ok', 'note' => 'attempt_terminal_' . $attempt->status]);
        }

        if ($attempt) {
            $inquiry = $attempt->inquiry;

            if (! $inquiry) {
                // Orphan: attempt row exists but inquiry was deleted. Return 200
                // so Octo does not retry — a retry cannot recover a deleted inquiry.
                Log::error('Octo callback: attempt found but parent inquiry missing (orphan)', [
                    'transaction_id' => $transactionId,
                    'attempt_id'     => $attempt->id,
                ]);

                return response()->json(['status' => 'ok', 'note' => 'orphan_attempt']);
            }
        } else {
            // Fallback for pre-Phase-1 links with no attempt row. Remove this
            // branch in Phase 5 once all legacy links have paid or expired.
            $inquiry = BookingInquiry::where('octo_transaction_id', $transactionId)->first();

            if ($inquiry) {
                Log::info('Octo callback: resolved via legacy octo_transaction_id (no attempt row)', [
                    'transaction_id' => $transactionId,
                    'reference'      => $inquiry->reference,
                ]);
            }
        }

        if (! $inquiry) {
            Log::warning('Octo callback: no inquiry matching transaction', [
                'transaction_id' => $transactionId,
            ]);

            return response()->json(['error' => 'Inquiry not found'], 404);
        }

        // Guard 1: idempotency — duplicate webhook retry.
        if ($inquiry->paid_at !== null) {
            Log::info('BookingInquiry: duplicate webhook ignored (already paid)', [
                'reference'       => $inquiry->reference,
                'transaction_id'  => $transactionId,
                'already_paid_at' => $inquiry->paid_at->toIso8601String(),
                'incoming_status' => $status,
            ]);

            return response()->json(['status' => 'ok', 'note' => 'already_paid']);
        }

        if ($status === 'success') {
            // Guard 2: terminal-status check — don't silently revive a
            // cancelled or spam-marked inquiry. Store payment info for
            // the audit trail, then ping ops for human review.
            if (in_array($inquiry->status, [
                BookingInquiry::STATUS_CANCELLED,
                BookingInquiry::STATUS_SPAM,
            ], true)) {
                $priorStatus = $inquiry->status;

                $inquiry->update([
                    // DO NOT change status — human review required.
                    'payment_method'      => BookingInquiry::PAYMENT_ONLINE,
                    'paid_at'             => now(),
                    'internal_notes'      => ($inquiry->internal_notes ? $inquiry->internal_notes . "\n\n" : '')
                        . '[' . now()->format('Y-m-d H:i') . '] ⚠️ PAYMENT RECEIVED ON ' . mb_strtoupper($priorStatus)
                        . " INQUIRY — human review required. txn={$transactionId}",
                ]);

                // Stamp attempt paid — money received even under terminal-status review.
                $this->stampAttempt($attempt, OctoPaymentAttempt::STATUS_PAID, $transactionId);

                Log::error('BookingInquiry: payment received on terminal-status inquiry', [
                    'reference'      => $inquiry->reference,
                    'prior_status'   => $priorStatus,
                    'transaction_id' => $transactionId,
                    'uzs_paid'       => $paidSum,
                ]);

                try {
                    app(BookingInquiryNotifier::class)
                        ->notifyPaidOnTerminalStatus($inquiry, $priorStatus, (string) $paidSum);
                } catch (\Throwable $e) {
                    Log::warning('BookingInquiryNotifier::notifyPaidOnTerminalStatus failed', [
                        'reference' => $inquiry->reference,
                        'error'     => $e->getMessage(),
                    ]);
                }

                return response()->json(['status' => 'ok', 'note' => 'terminal_status_review_required']);
            }

            $inquiry->update([
                'status'         => BookingInquiry::STATUS_CONFIRMED,
                'payment_method' => BookingInquiry::PAYMENT_ONLINE,
                'confirmed_at'   => $inquiry->confirmed_at ?: now(),
            ]);

            // Phase 16.3 — record as guest payment. Observer will auto-set
            // paid_at + closed_by_user_id when received sum reaches price_quoted.
            //
            // Split-payment: amount_online_usd is what Octo actually charged.
            // Fall back to price_quoted for legacy rows (pre-split migration)
            // that have no amount_online_usd populated. Recording the quote
            // total on a partial payment would silently over-credit the guest
            // and flip paid_at prematurely — that is the bug this guards.
            $recordedAmount = (float) ($inquiry->amount_online_usd ?? $inquiry->price_quoted ?? 0);
            $paymentType    = $recordedAmount >= (float) ($inquiry->price_quoted ?? 0)
                ? GuestPayment::TYPE_FULL
                : GuestPayment::TYPE_BALANCE;

            \App\Models\GuestPayment::create([
                'booking_inquiry_id' => $inquiry->id,
                'amount'             => $recordedAmount,
                'currency'           => 'USD',
                'payment_type'       => $paymentType,
                'payment_method'     => 'octo',
                'payment_date'       => now()->toDateString(),
                'reference'          => (string) $transactionId,
                'status'             => 'recorded',
            ]);

            // Stamp attempt paid after inquiry confirmed + GuestPayment recorded.
            $this->stampAttempt($attempt, OctoPaymentAttempt::STATUS_PAID, $transactionId);

            Log::info('BookingInquiry marked paid via Octo', [
                'reference'      => $inquiry->reference,
                'transaction_id' => $transactionId,
                'uzs_paid'       => $paidSum,
                'via_attempt'    => $attempt?->id,
            ]);

            try {
                app(BookingInquiryNotifier::class)->notifyPaid($inquiry, (string) $paidSum);
            } catch (\Throwable $e) {
                // Never fail the webhook response because of a notifier hiccup.
                Log::warning('BookingInquiryNotifier::notifyPaid failed', [
                    'reference' => $inquiry->reference,
                    'error'     => $e->getMessage(),
                ]);
            }

            // Guest receipt — email + WhatsApp. Failure must never block the 200.
            try {
                app(\App\Actions\Payment\SendReceiptAction::class)
                    ->execute($inquiry, force: false, uzsAmountRaw: (string) $paidSum);
            } catch (\Throwable $e) {
                Log::warning('SendReceiptAction failed in webhook', [
                    'reference' => $inquiry->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        } else {
            // Append audit note for traceability.
            $stamp    = now()->format('Y-m-d H:i');
            $existing = $inquiry->internal_notes ? $inquiry->internal_notes . "\n\n" : '';

            // Inverted whitelist: only KNOWN soft states leave the attempt
            // and inquiry alone. Anything else — known hard failures
            // (failed/declined/canceled/expired) AND any unknown future
            // status — is treated as TERMINAL and triggers Fix A reset.
            // The cost of a false-terminal is one extra regenerate click;
            // the cost of a false-non-terminal is the operator being locked
            // out (Sable INQ-2026-000069 bug). Safe default = terminal.
            $nonTerminalStatuses = [
                'created',
                'wait_user_action',
                'process_user_login',
                'process_action',
                'payment_in_process',
            ];
            $isTerminal = ! in_array(strtolower((string) $status), $nonTerminalStatuses, true);

            $updates = [
                'internal_notes' => $existing . "[{$stamp}] Octo payment {$status} (txn {$transactionId})",
            ];

            if ($isTerminal && empty($inquiry->paid_at)) {
                // Fix A: clear the dead payment_link and revert awaiting_payment
                // so operator gets the standard "Generate & send" action back.
                $updates['payment_link']         = null;
                $updates['payment_link_sent_at'] = null;
                if ($inquiry->status === BookingInquiry::STATUS_AWAITING_PAYMENT) {
                    $updates['status'] = BookingInquiry::STATUS_CONTACTED;
                }
            }

            // System-state write (status, payment_link, internal_notes) — forceFill()->save()
            // per the operational-timestamps rule. update() silently drops fields
            // missing from $fillable, which masks bugs (incident 2026-04-26).
            $inquiry->forceFill($updates)->save();

            // Only stamp the attempt FAILED when the callback is TERMINAL.
            // Non-terminal statuses like 'wait_user_action' just mean the
            // customer hasn't paid yet — the URL is still valid and the
            // attempt must stay 'active' so the regenerate UI stays hidden
            // (operator shouldn't disrupt an in-flight payment session).
            if ($isTerminal) {
                $this->stampAttempt($attempt, OctoPaymentAttempt::STATUS_FAILED, $transactionId);
            }

            Log::warning('BookingInquiry payment unsuccessful', [
                'reference'      => $inquiry->reference,
                'transaction_id' => $transactionId,
                'status'         => $status,
                'via_attempt'    => $attempt?->id,
                'terminal'       => $isTerminal,
                'reset_link'     => $isTerminal && empty($inquiry->paid_at),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Stamp the attempt's terminal status (paid/failed) after the inquiry
     * has been updated. Independent try/catch so a DB hiccup here never
     * withholds the 200 response or rolls back the confirmed inquiry.
     * No-ops silently when $attempt is null (legacy path, no attempt row).
     */
    private function stampAttempt(?OctoPaymentAttempt $attempt, string $newStatus, string $transactionId): void
    {
        if (! $attempt) {
            return;
        }

        try {
            $attempt->update(['status' => $newStatus]);
        } catch (\Throwable $e) {
            Log::error('Octo callback: failed to stamp attempt status', [
                'attempt_id'     => $attempt->id,
                'transaction_id' => $transactionId,
                'target_status'  => $newStatus,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase S — signature guard.
     *
     * Step 1: presence check — real Octo callbacks always carry a `signature`
     * field. Missing signature → likely a probe or spoof → 403 immediately,
     * regardless of the feature flag.
     *
     * Step 2: cryptographic check (flag-gated). Logs candidate hashes from
     * known fields against the received signature so we can confirm Octo's
     * exact scheme from the first real callback after deployment. Once
     * confirmed, flip `services.octo.verify_callback_signature` to true.
     *
     * The method NEVER throws — it returns a JSON 403 response on failure
     * and the caller returns it, or it returns null to continue.
     */
    private function guardSignature(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $received   = (string) $request->input('signature', '');
        $octoUuid   = (string) $request->input('octo_payment_UUID', '');
        $shopTxn    = (string) $request->input('shop_transaction_id', '');
        $status     = (string) $request->input('status', '');
        $uniqueKey  = (string) config('services.octo.unique_key', '');

        // Step 1 — presence check (always enforced).
        if ($received === '') {
            Log::warning('Octo callback: missing signature field — possible probe or spoof', [
                'transaction_id' => $shopTxn,
                'status'         => $status,
            ]);
            return response()->json(['error' => 'signature required'], 403);
        }

        // Step 2 — verify signature.
        // Formula confirmed from help.octo.uz/en/notifications:
        //   SHA1( unique_key + octo_payment_UUID + status )
        // unique_key is a separate Octo credential (NOT octo_secret).
        // Set OCTO_UNIQUE_KEY in .env (get from merchant.octo.uz → Integration
        // settings, or ask Octo support for shop_id 27061).
        $candidates = [
            'sha1(uniqueKey+octoUuid+status)' => strtoupper(sha1($uniqueKey . $octoUuid . $status)),
        ];

        $matchedScheme = null;
        foreach ($candidates as $name => $val) {
            if ($val === $received) {
                $matchedScheme = $name;
                break;
            }
        }

        Log::info('Octo callback: signature check', [
            'transaction_id'  => $shopTxn,
            'matched'         => $matchedScheme !== null,
            'unique_key_set'  => $uniqueKey !== '',
            'enforce'         => (bool) config('services.octo.verify_callback_signature', false),
        ]);

        // Step 3 — enforce only when flag is on.
        if ((bool) config('services.octo.verify_callback_signature', false)) {
            if ($matchedScheme === null) {
                Log::warning('Octo callback: signature mismatch — rejecting', [
                    'received'       => $received,
                    'transaction_id' => $shopTxn,
                ]);
                return response()->json(['error' => 'invalid signature'], 403);
            }
        }

        return null;
    }

    public function success(Request $request)
    {
        $status  = $request->query('octo_status', 'unknown');
        $message = $status === 'succeeded'
            ? 'Your payment was successful! 🎉'
            : 'Payment status: ' . ucfirst($status);

        return view('payment.success', compact('message', 'status'));
    }
}
