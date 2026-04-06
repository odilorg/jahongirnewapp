<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteBookingRequest;
use App\Services\WebsiteBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Handles POST /api/bookings/website
 *
 * Intentionally thin: all business logic lives in WebsiteBookingService.
 * This controller's only responsibilities are:
 *   1. Pass the validated + normalised request to the service
 *   2. Return a consistent JSON response
 *   3. Catch unexpected failures and return 500 without leaking internals
 */
class WebsiteBookingController extends Controller
{
    public function __construct(private readonly WebsiteBookingService $service) {}

    public function store(WebsiteBookingRequest $request): JsonResponse
    {
        $data = $request->toBookingData();

        Log::info('WebsiteBookingController: incoming request', [
            'tour'  => $data['tour'],
            'name'  => $data['name'],
            'date'  => $data['date'],
            'ip'    => $request->ip(),
            // email logged at info level — it is not a secret, it is the booking identifier
            'email' => $data['email'],
        ]);

        try {
            ['booking' => $booking, 'created' => $created] = $this->service->createFromWebsite($data);
        } catch (\Throwable $e) {
            Log::error('WebsiteBookingController: unexpected failure', [
                'message' => $e->getMessage(),
                'tour'    => $data['tour'],
                'email'   => $data['email'],
                'date'    => $data['date'],
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Booking could not be created. Please try again.',
            ], 500);
        }

        $status = $created ? 201 : 200;

        return response()->json([
            'status'         => $created ? 'created' : 'duplicate',
            'booking_number' => $booking->booking_number,
            'message'        => $created
                ? 'Booking received. We will confirm within 24 hours.'
                : 'Booking already registered.',
        ], $status);
    }
}
