<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Beds24BookingServiceTest extends TestCase
{
    private const AUTH_URL     = 'https://beds24.com/api/v2/authentication/token';
    private const BOOKINGS_URL = 'https://api.beds24.com/v2/bookings';

    protected function setUp(): void
    {
        parent::setUp();

        // Provide a fake refresh token so the service can attempt token refresh
        config(['services.beds24.api_v2_refresh_token' => 'fake-refresh-token-for-tests']);
    }

    /**
     * Fake both the token endpoint and the bookings endpoint.
     * The token fake always returns a valid token so the service can proceed.
     */
    private function fakeWithBookingsResponse(mixed $bookingsBody, int $bookingsStatus = 200): void
    {
        Http::fake([
            self::AUTH_URL     => Http::response([
                'token'        => 'fake-access-token',
                'expiresIn'    => 86400,
                'refreshToken' => 'rotated-fake-refresh',
            ], 200),
            self::BOOKINGS_URL => Http::response($bookingsBody, $bookingsStatus),
        ]);
    }

    // ── writePaymentItem payload ───────────────────────────────────────────────

    public function test_write_payment_item_posts_correct_payload(): void
    {
        $this->fakeWithBookingsResponse([['success' => true]]);

        $service = new Beds24BookingService();
        $service->writePaymentItem(
            123456,
            100.0,
            'Cash payment — BOT-PMT|123456|10000|cash|john|2025-06-01'
        );

        Http::assertSent(function ($request) {
            if ($request->url() !== self::BOOKINGS_URL) {
                return false; // skip token refresh request
            }

            $body = $request->data();
            $item = $body[0] ?? null;

            return $item !== null
                && $item['id'] === 123456
                && isset($item['invoiceItems'][0])
                && $item['invoiceItems'][0]['type'] === '2'
                && $item['invoiceItems'][0]['amount'] === 100.0
                && str_contains($item['invoiceItems'][0]['description'], 'BOT-PMT');
        });
    }

    public function test_write_payment_item_throws_on_http_failure(): void
    {
        $this->fakeWithBookingsResponse([], 500);

        $service = new Beds24BookingService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/writePaymentItem failed.*HTTP 500/');

        $service->writePaymentItem(123456, 100.0, 'test description');
    }

    public function test_write_payment_item_throws_on_beds24_error_response(): void
    {
        $this->fakeWithBookingsResponse([[
            'success' => false,
            'errors'  => [['code' => 'INVALID_BOOKING', 'msg' => 'Booking not found']],
        ]]);

        $service = new Beds24BookingService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/errors/');

        $service->writePaymentItem(999, 50.0, 'test');
    }
}
