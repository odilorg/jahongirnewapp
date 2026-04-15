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
     * On success: mark confirmed, stamp paid_at, fire Telegram notification.
     * On failure: keep inquiry in awaiting_payment so operator can resend
     * a link or follow up. We do NOT auto-cancel — a failed first attempt
     * often becomes a successful retry.
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

        if ($status === 'success') {
            $inquiry->update([
                'status'         => BookingInquiry::STATUS_CONFIRMED,
                'payment_method' => BookingInquiry::PAYMENT_ONLINE,
                'paid_at'        => now(),
                'confirmed_at'   => $inquiry->confirmed_at ?: now(),
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
