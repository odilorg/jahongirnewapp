<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CreateBookingRequest;
use Tests\TestCase;

class CreateBookingRequestTest extends TestCase
{
    private function baseValid(): array
    {
        return [
            'guest' => ['name' => 'John Walker', 'phone' => '+998901234567', 'email' => 'john@example.com'],
            'dates' => ['check_in' => '2025-06-01', 'check_out' => '2025-06-03'],
            'room'  => ['unit_name' => '12'],
        ];
    }

    // ──────────────────────────────────────────────
    // fromParsed()
    // ──────────────────────────────────────────────

    /** @test */
    public function it_builds_from_valid_parsed_array(): void
    {
        $req = CreateBookingRequest::fromParsed($this->baseValid(), 'Staff Name');

        $this->assertSame('John Walker',        $req->guestName);
        $this->assertSame('+998901234567',       $req->guestPhone);
        $this->assertSame('john@example.com',   $req->guestEmail);
        $this->assertSame('2025-06-01',         $req->checkIn);
        $this->assertSame('2025-06-03',         $req->checkOut);
        $this->assertSame('Staff Name',         $req->createdBy);
        $this->assertCount(1, $req->rooms);
    }

    /** @test */
    public function it_trims_whitespace_from_string_fields(): void
    {
        $parsed = $this->baseValid();
        $parsed['guest']['name'] = '  John Walker  ';
        $parsed['dates']['check_in'] = '  2025-06-01  ';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertSame('John Walker', $req->guestName);
        $this->assertSame('2025-06-01',  $req->checkIn);
    }

    // ──────────────────────────────────────────────
    // validationError() — presence checks
    // ──────────────────────────────────────────────

    /** @test */
    public function it_passes_validation_for_valid_request(): void
    {
        $req = CreateBookingRequest::fromParsed($this->baseValid(), 'Staff');
        $this->assertNull($req->validationError());
    }

    /** @test */
    public function it_rejects_missing_guest_name(): void
    {
        $parsed = $this->baseValid();
        $parsed['guest']['name'] = '';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('guest name', $req->validationError());
    }

    /** @test */
    public function it_rejects_missing_check_in(): void
    {
        $parsed = $this->baseValid();
        $parsed['dates']['check_in'] = '';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('date', $req->validationError());
    }

    /** @test */
    public function it_rejects_missing_check_out(): void
    {
        $parsed = $this->baseValid();
        $parsed['dates']['check_out'] = '';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('date', $req->validationError());
    }

    /** @test */
    public function it_rejects_missing_rooms(): void
    {
        $parsed = $this->baseValid();
        unset($parsed['room']);

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('room', $req->validationError());
    }

    // ──────────────────────────────────────────────
    // validationError() — date order
    // ──────────────────────────────────────────────

    /** @test */
    public function it_rejects_check_out_before_check_in(): void
    {
        $parsed = $this->baseValid();
        $parsed['dates']['check_in']  = '2025-06-05';
        $parsed['dates']['check_out'] = '2025-06-01';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('before', $req->validationError());
    }

    /** @test */
    public function it_rejects_same_day_checkin_checkout(): void
    {
        $parsed = $this->baseValid();
        $parsed['dates']['check_in']  = '2025-06-01';
        $parsed['dates']['check_out'] = '2025-06-01';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertStringContainsString('before', $req->validationError());
    }

    /** @test */
    public function it_rejects_unparseable_dates(): void
    {
        $parsed = $this->baseValid();
        $parsed['dates']['check_in'] = 'not-a-date';

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNotNull($req->validationError());
    }

    // ──────────────────────────────────────────────
    // validationError() — duplicate rooms
    // ──────────────────────────────────────────────

    /** @test */
    public function it_rejects_duplicate_room_requests(): void
    {
        $parsed = $this->baseValid();
        $parsed['rooms'] = [
            ['unit_name' => '12'],
            ['unit_name' => '12'],
            ['unit_name' => '14'],
        ];
        unset($parsed['room']);

        $req   = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $error = $req->validationError();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Duplicate', $error);
        $this->assertStringContainsString('12', $error);
    }

    /** @test */
    public function it_passes_for_different_rooms_with_same_number_but_different_properties(): void
    {
        // Room "12" at hotel and "12" at premium are distinct — should not be a duplicate error
        $parsed = $this->baseValid();
        $parsed['rooms'] = [
            ['unit_name' => '12', 'property' => 'hotel'],
            ['unit_name' => '12', 'property' => 'premium'],
        ];
        unset($parsed['room']);

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNull($req->validationError());
    }

    // ──────────────────────────────────────────────
    // validationError() — optional finance
    // ──────────────────────────────────────────────

    /** @test */
    public function it_passes_when_no_price_key_is_present(): void
    {
        // price absent → finance is null → no validation error from finance
        $parsed = $this->baseValid();

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNull($req->finance);
        $this->assertNull($req->validationError());
    }

    /** @test */
    public function it_passes_when_price_is_explicitly_null(): void
    {
        $parsed           = $this->baseValid();
        $parsed['price']  = null;

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNull($req->finance);
        $this->assertNull($req->validationError());
    }

    /** @test */
    public function it_passes_with_a_valid_quoted_total(): void
    {
        $parsed          = $this->baseValid();
        $parsed['price'] = 200.0;

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNotNull($req->finance);
        $this->assertSame(200.0, $req->finance->quotedTotal);
        $this->assertNull($req->validationError());
    }

    /** @test */
    public function it_rejects_a_zero_quoted_total(): void
    {
        $parsed          = $this->baseValid();
        $parsed['price'] = 0;

        $req   = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $error = $req->validationError();

        $this->assertNotNull($error);
        $this->assertStringContainsString('greater than zero', $error);
    }

    /** @test */
    public function it_rejects_a_negative_quoted_total(): void
    {
        $parsed          = $this->baseValid();
        $parsed['price'] = -50;

        $req = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $this->assertNotNull($req->validationError());
    }

    /** @test */
    public function finance_validation_fires_after_room_check(): void
    {
        // No rooms + invalid price: room error should surface first
        $parsed = $this->baseValid();
        unset($parsed['room']);
        $parsed['price'] = -10;

        $req   = CreateBookingRequest::fromParsed($parsed, 'Staff');
        $error = $req->validationError();

        $this->assertStringContainsString('room', $error);
    }
}
