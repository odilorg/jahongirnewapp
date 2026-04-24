<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use App\Services\BookingInquiryNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OctoCallbackController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Octo callback data:', $request->all());

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
     * Lookup by octo_transaction_id (indexed) — no regex parsing needed.
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
        $inquiry = BookingInquiry::where('octo_transaction_id', $transactionId)->first();

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

            Log::info('BookingInquiry marked paid via Octo', [
                'reference'      => $inquiry->reference,
                'transaction_id' => $transactionId,
                'uzs_paid'       => $paidSum,
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
        } else {
            // Leave status as-is (probably still awaiting_payment) so the
            // operator can retry. Append an audit note for traceability.
            $stamp    = now()->format('Y-m-d H:i');
            $existing = $inquiry->internal_notes ? $inquiry->internal_notes . "\n\n" : '';
            $inquiry->update([
                'internal_notes' => $existing . "[{$stamp}] Octo payment {$status} (txn {$transactionId})",
            ]);

            Log::warning('BookingInquiry payment unsuccessful', [
                'reference'      => $inquiry->reference,
                'transaction_id' => $transactionId,
                'status'         => $status,
            ]);
        }

        return response()->json(['status' => 'ok']);
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
