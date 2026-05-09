<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInquiry;
use App\Models\Guest;
use App\Services\OctoPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lock the contract that Octo /prepare_payment payloads carry the `ttl`
 * value sourced from `services.octo.payment_link_ttl_minutes` (default 5000).
 *
 * Why this test exists: the prior code had `'ttl' => 5000` hard-coded in two
 * places. After config-extraction, a future env-tweak / typo could silently
 * change the link lifetime. This test asserts the actual JSON sent to Octo
 * carries the configured value on BOTH `createPaymentLinkForInquiry`
 * (booking_inquiries path — used by hotel TG bot, website inquiries) AND
 * `createPaymentLink` (legacy bookings path).
 *
 * Default behavior unchanged: ttl stays 5000 unless OCTO_PAYMENT_LINK_TTL_MINUTES
 * is set in the environment.
 */
class OctoPaymentTtlConfigTest extends TestCase
{
    use RefreshDatabase;

    private const OCTO_URL = 'https://secure.octo.uz/prepare_payment';
    private const SUCCESS_BODY = [
        'error' => 0,
        'data'  => [
            'octo_pay_url'        => 'https://pay2.octo.uz/pay/test-uuid',
            'shop_transaction_id' => 'inquiry_1_mock',
            'status'              => 'created',
            'total_sum'           => 1_000_000,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.octo.shop_id'               => 27061,
            'services.octo.secret'                => 'shop-secret',
            'services.octo.url'                   => self::OCTO_URL,
            'services.octo.tsp_id'                => null,
            'services.octo.relay_url'             => null,
            'services.octo.fallback_usd_uzs_rate' => 12500,
            'cache.default'                       => 'array',
        ]);
    }

    private function makeInquiry(): BookingInquiry
    {
        return BookingInquiry::create([
            'reference'          => 'INQ-TTL-'.uniqid(),
            'source'             => 'website',
            'status'             => BookingInquiry::STATUS_NEW,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'customer_email'     => 't@e.st',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'people_children'    => 0,
            'submitted_at'       => now(),
        ]);
    }

    private function makeBooking(): Booking
    {
        $guest = Guest::create([
            'name'  => 'Test Guest',
            'phone' => '+998901234567',
            'email' => 't@e.st',
        ]);

        return Booking::create([
            'guest_id'                => $guest->id,
            'booking_start_date_time' => now()->addDays(7),
            'booking_end_date_time'   => now()->addDays(8),
            'amount'                  => 100,
            'grand_total'             => 100,
            'payment_status'          => 'pending',
            'booking_status'          => 'pending',
            'booking_source'          => 'manual',
            'number_of_people'        => 2,
            'booking_number'          => 'TEST-'.uniqid(),
        ]);
    }

    private function fakeOctoSuccess(): void
    {
        Http::fake([
            'cbu.uz/*'                 => Http::response(null, 500),
            'open.er-api.com/*'        => Http::response(null, 500),
            'cdn.jsdelivr.net/*'       => Http::response(null, 500),
            'currency-api.pages.dev/*' => Http::response(null, 500),
            self::OCTO_URL             => Http::response(self::SUCCESS_BODY, 200),
        ]);
    }

    public function test_default_ttl_is_5000_minutes(): void
    {
        $this->fakeOctoSuccess();

        // Don't override the config key — relies on services.php default.
        app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        Http::assertSent(function ($req) {
            return $req->url() === self::OCTO_URL
                && ($req->data()['ttl'] ?? null) === 5000;
        });
    }

    public function test_ttl_is_overrideable_via_config(): void
    {
        config(['services.octo.payment_link_ttl_minutes' => 1440]); // 1 day

        $this->fakeOctoSuccess();

        app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        Http::assertSent(function ($req) {
            return $req->url() === self::OCTO_URL
                && ($req->data()['ttl'] ?? null) === 1440;
        });
    }

    public function test_ttl_falls_back_to_5000_when_config_is_null(): void
    {
        // Defense-in-depth: if a misconfigured env returns null (e.g. operator
        // sets `OCTO_PAYMENT_LINK_TTL_MINUTES=` empty in .env), the inline
        // `?? 5000` fallback in OctoPaymentService preserves the prior
        // hard-coded value rather than shipping `ttl: 0` (instant expiry).
        config(['services.octo.payment_link_ttl_minutes' => null]);

        $this->fakeOctoSuccess();

        app(OctoPaymentService::class)
            ->createPaymentLinkForInquiry($this->makeInquiry(), 100.00);

        Http::assertSent(function ($req) {
            return $req->url() === self::OCTO_URL
                && ($req->data()['ttl'] ?? null) === 5000;
        });
    }

    public function test_legacy_booking_path_also_uses_ttl_config(): void
    {
        // The booking path (createPaymentLink) and the inquiry path
        // (createPaymentLinkForInquiry) MUST read the same config key —
        // otherwise a future tweak on one branch silently diverges.
        $this->fakeOctoSuccess();

        app(OctoPaymentService::class)
            ->createPaymentLink($this->makeBooking(), 100.00);

        Http::assertSent(function ($req) {
            return $req->url() === self::OCTO_URL
                && ($req->data()['ttl'] ?? null) === 5000;
        });
    }
}
