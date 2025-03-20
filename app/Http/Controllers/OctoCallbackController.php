<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\GuestPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OctoCallbackController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Octo callback data:', $request->all());

        // Possibly "shop_transaction_id" indicates which booking we have, 
        // or you used "transaction_param"? Check the doc
        $transactionId = $request->input('shop_transaction_id'); 
        $status        = $request->input('status'); // e.g. "success", "failed"
        $paidSum       = $request->input('total_sum'); // might be e.g. "100.0"

        // Because we stored "booking_{id}" in shop_transaction_id, extract the actual ID
        // For example, if "shop_transaction_id" = "booking_12_xxxrandom"
        $bookingId = null;
        if (preg_match('/^booking_(\d+)_/i', $transactionId, $matches)) {
            $bookingId = $matches[1] ?? null;
        }

        if (!$bookingId) {
            return response()->json(['error' => 'Could not extract booking ID from transaction_id'], 400);
        }

        $booking = Booking::find($bookingId);
        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        // Check status:
        if ($status === 'success') {
            $booking->payment_status = 'paid';
            $booking->save();

            // Create a GuestPayment record for your Filament resource
            GuestPayment::create([
                'guest_id'       => $booking->guest_id,
                'booking_id'     => $booking->id,
                'amount'         => $paidSum,  // decimal
                'payment_date'   => now(),
                'payment_method' => 'card',
                'payment_status' => 'paid',
            ]);

            Log::info("Booking #{$booking->id} marked paid, sum={$paidSum}.");
        } else {
            // Payment not successful
            $booking->payment_status = 'failed';
            $booking->save();

            Log::warning("Booking #{$booking->id} payment failed or canceled.");
        }

        // Return a 200 so Octo knows you processed it
        return response()->json(['status' => 'ok']);
    }

    public function success(Request $request)
{
    $status = $request->query('octo_status', 'unknown');
    $message = $status === 'succeeded' ? "Your payment was successful! 🎉" : "Payment status: " . ucfirst($status);

    return view('payment.success', compact('message', 'status'));
}

}
