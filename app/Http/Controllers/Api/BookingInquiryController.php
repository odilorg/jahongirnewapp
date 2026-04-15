<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingInquiryRequest;
use App\Models\BookingInquiry;
use App\Services\BookingInquiryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public endpoint: POST /api/v1/inquiries
 *
 * Receives website leads from jahongir-travel.uz (proxied through Nginx) and
 * other future sources. Intentionally thin: validation lives in the FormRequest,
 * notification lives in the BookingInquiryNotifier service.
 *
 * Contract: always returns 201 on valid input, even for honeypot-tripped
 * submissions. This denies bots the signal they need to tune their payload.
 */
class BookingInquiryController extends Controller
{
    public function __construct(
        private readonly BookingInquiryNotifier $notifier,
    ) {}

    public function store(StoreBookingInquiryRequest $request): JsonResponse
    {
        $data = $request->toInquiryData();

        // Provenance — set server-side so clients cannot forge it.
        $data['reference']    = BookingInquiry::generateReference();
        $data['ip_address']   = $request->ip();
        $data['user_agent']   = mb_substr((string) $request->userAgent(), 0, 500);
        $data['submitted_at'] = now();

        // Honeypot: accept the row silently so the bot gets a 201, but flag it.
        if ($request->isLikelySpam()) {
            $data['status'] = BookingInquiry::STATUS_SPAM;
        }

        try {
            $inquiry = DB::transaction(fn () => BookingInquiry::create($data));
        } catch (Throwable $e) {
            Log::error('BookingInquiry: failed to persist', [
                'error' => $e->getMessage(),
                'email' => $data['customer_email'] ?? null,
                'tour'  => $data['tour_name_snapshot'] ?? null,
            ]);

            // Never 500 the public form — return a generic accepted response.
            // The caller (mailer-tours.php) has already sent email + Telegram,
            // so the lead is not lost even if persistence fails.
            return response()->json([
                'ok'        => false,
                'reference' => null,
                'message'   => 'Inquiry accepted but not persisted.',
            ], 202);
        }

        // Fire notification for non-spam only — bots don't need Telegram pings.
        if ($inquiry->status !== BookingInquiry::STATUS_SPAM) {
            try {
                $this->notifier->notify($inquiry);
            } catch (Throwable $e) {
                // Notification failure must not break the API response.
                Log::warning('BookingInquiry: notifier failed', [
                    'reference' => $inquiry->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ok'        => true,
            'reference' => $inquiry->reference,
        ], 201);
    }
}
