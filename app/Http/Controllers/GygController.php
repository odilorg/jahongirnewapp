<?php

namespace App\Http\Controllers;

use App\Services\GygService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GygController extends Controller
{
    public function __construct(private readonly GygService $gygService)
    {
    }

    // -------------------------------------------------------------------------
    // GET /api/gyg/1/get-availabilities/
    // -------------------------------------------------------------------------

    public function getAvailabilities(Request $request): JsonResponse
    {
        try {
            $productId = $request->query('productId');
            $from      = $request->query('fromDateTime');
            $to        = $request->query('toDateTime');

            if (! $productId || ! $from || ! $to) {
                return $this->json([
                    'errorCode'    => 'VALIDATION_FAILURE',
                    'errorMessage' => 'Required query params: productId, fromDateTime, toDateTime',
                ]);
            }

            $result = $this->gygService->getAvailabilities($productId, $from, $to);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'getAvailabilities');
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/gyg/1/reserve/
    // -------------------------------------------------------------------------

    public function reserve(Request $request): JsonResponse
    {
        try {
            $data = $request->input('data', []);

            if (empty($data)) {
                return $this->json([
                    'errorCode'    => 'VALIDATION_FAILURE',
                    'errorMessage' => 'Request body must contain a data object',
                ]);
            }

            $result = $this->gygService->reserve($data);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'reserve');
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/gyg/1/cancel-reservation/
    // -------------------------------------------------------------------------

    public function cancelReservation(Request $request): JsonResponse
    {
        try {
            $data = $request->input('data', []);

            if (empty($data)) {
                return $this->json([
                    'errorCode'    => 'VALIDATION_FAILURE',
                    'errorMessage' => 'Request body must contain a data object',
                ]);
            }

            $result = $this->gygService->cancelReservation($data);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'cancelReservation');
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/gyg/1/book/
    // -------------------------------------------------------------------------

    public function book(Request $request): JsonResponse
    {
        try {
            $data = $request->input('data', []);

            if (empty($data)) {
                return $this->json([
                    'errorCode'    => 'VALIDATION_FAILURE',
                    'errorMessage' => 'Request body must contain a data object',
                ]);
            }

            $result = $this->gygService->book($data);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'book');
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/gyg/1/cancel-booking/
    // -------------------------------------------------------------------------

    public function cancelBooking(Request $request): JsonResponse
    {
        try {
            $data = $request->input('data', []);

            if (empty($data)) {
                return $this->json([
                    'errorCode'    => 'VALIDATION_FAILURE',
                    'errorMessage' => 'Request body must contain a data object',
                ]);
            }

            $result = $this->gygService->cancelBooking($data);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'cancelBooking');
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/gyg/1/notify/
    // -------------------------------------------------------------------------

    public function notify(Request $request): JsonResponse
    {
        try {
            $data = $request->input('data', []);
            $result = $this->gygService->notify($data);
            return $this->json($result);
        } catch (Throwable $e) {
            return $this->internalError($e, 'notify');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Always return HTTP 200 per GYG spec.
     */
    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200);
    }

    private function internalError(Throwable $e, string $action): JsonResponse
    {
        Log::error('GYG ' . $action . ' error: ' . $e->getMessage(), [
            'exception' => $e,
        ]);

        return $this->json([
            'errorCode'    => 'INTERNAL_SYSTEM_FAILURE',
            'errorMessage' => 'An internal error occurred',
        ]);
    }
}
