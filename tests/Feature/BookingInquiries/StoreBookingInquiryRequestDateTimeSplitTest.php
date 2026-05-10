<?php

declare(strict_types=1);

namespace Tests\Feature\BookingInquiries;

use App\Http\Requests\StoreBookingInquiryRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Regression for inquiry 109: the website form sent
 * travel_date='2026-06-02T09:00' and the date-typed DB column silently
 * stripped the time. After the fix, toInquiryData() must split the input
 * into date-only travel_date + pickup_time when a real time is present.
 */
class StoreBookingInquiryRequestDateTimeSplitTest extends TestCase
{
    /**
     * Build a StoreBookingInquiryRequest manually so we can call
     * toInquiryData() without the rest of the controller stack.
     */
    private function build(array $payload): StoreBookingInquiryRequest
    {
        $req = StoreBookingInquiryRequest::create('/api/v1/inquiries', 'POST', $payload);
        $req->setContainer(app());
        $req->setRedirector(app(\Illuminate\Routing\Redirector::class));
        $req->validateResolved();
        return $req;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'tour_name_snapshot' => 'Test Tour',
            'customer_name'      => 'Andrea',
            'customer_email'     => 'a@b.test',
            'customer_phone'     => '+998901234567',
            'people_adults'      => 2,
        ], $overrides);
    }

    public function test_iso_datetime_is_split_into_date_and_pickup_time(): void
    {
        $req = $this->build($this->basePayload([
            'travel_date' => '2026-06-02T09:00',
        ]));

        $data = $req->toInquiryData();
        $this->assertSame('2026-06-02', $data['travel_date']);
        $this->assertSame('09:00:00', $data['pickup_time']);
    }

    public function test_date_only_input_yields_null_pickup_time(): void
    {
        // A date-only form submission (legacy / minimal client) MUST NOT
        // result in pickup_time being silently set to 00:00:00 — that
        // would mislead operators about the actual pickup time.
        $req = $this->build($this->basePayload([
            'travel_date' => '2026-06-02',
        ]));

        $data = $req->toInquiryData();
        $this->assertSame('2026-06-02', $data['travel_date']);
        $this->assertNull($data['pickup_time']);
    }

    public function test_explicit_pickup_time_wins_over_iso_datetime(): void
    {
        // If the form provides BOTH a separate pickup_time and an ISO
        // datetime in travel_date, the explicit pickup_time must win.
        $req = $this->build($this->basePayload([
            'travel_date' => '2026-06-02T09:00',
            'pickup_time' => '07:30',
        ]));

        $data = $req->toInquiryData();
        $this->assertSame('2026-06-02', $data['travel_date']);
        $this->assertSame('07:30:00', $data['pickup_time']);
    }

    public function test_missing_travel_date_does_not_break(): void
    {
        $req = $this->build($this->basePayload());

        $data = $req->toInquiryData();
        $this->assertNull($data['travel_date']);
        $this->assertNull($data['pickup_time']);
    }

    public function test_space_separated_datetime_also_split(): void
    {
        // RFC 3339 / SQL-style "YYYY-MM-DD HH:MM" must also be recognised.
        $req = $this->build($this->basePayload([
            'travel_date' => '2026-06-02 09:00',
        ]));

        $data = $req->toInquiryData();
        $this->assertSame('2026-06-02', $data['travel_date']);
        $this->assertSame('09:00:00', $data['pickup_time']);
    }
}
