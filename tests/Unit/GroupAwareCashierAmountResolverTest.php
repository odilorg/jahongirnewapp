<?php

namespace Tests\Unit;

use App\DTO\GroupAmountResolution;
use App\Models\Beds24Booking;
use App\Services\Beds24BookingService;
use App\Services\Cashier\GroupAwareCashierAmountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for GroupAwareCashierAmountResolver.
 *
 * Scenarios:
 *  (A) Standalone booking        → per-room amount, isSingleBooking = true
 *  (B) Group — all siblings local → sum of all sibling effectiveUsdAmount()
 *  (C) Group — count matches but sum includes same booking only once (no double-count)
 *  (D) Group — siblings incomplete, API fetch succeeds
 *  (E) Group — siblings incomplete, API fetch fails → IncompleteGroupSyncException
 */
class GroupAwareCashierAmountResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $attrs = []): Beds24Booking
    {
        return Beds24Booking::factory()->create(array_merge([
            'booking_status' => 'confirmed',
            'total_amount'   => 100.00,
            'invoice_balance' => 0,
        ], $attrs));
    }

    // -------------------------------------------------------------------------
    // (A) Standalone booking
    // -------------------------------------------------------------------------

    /** @test */
    public function standalone_booking_returns_per_room_amount(): void
    {
        $booking = $this->makeBooking([
            'beds24_booking_id' => 'STANDALONE_1',
            'master_booking_id' => null,
            'booking_group_size' => null,
            'total_amount'      => 150.00,
            'invoice_balance'   => 50.00,  // balance > 0 → effectiveUsdAmount = 50
        ]);

        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $beds24Svc->expects($this->never())->method($this->anything());

        $resolver = new GroupAwareCashierAmountResolver($beds24Svc);
        $result   = $resolver->resolve($booking);

        $this->assertInstanceOf(GroupAmountResolution::class, $result);
        $this->assertTrue($result->isSingleBooking);
        $this->assertEquals(50.00, $result->usdAmount);
        $this->assertNull($result->effectiveMasterBookingId);
        $this->assertNull($result->groupSizeExpected);
        $this->assertNull($result->groupSizeLocal);
    }

    // -------------------------------------------------------------------------
    // (B) Group — all siblings present locally
    // -------------------------------------------------------------------------

    /** @test */
    public function group_booking_sums_all_siblings_when_all_local(): void
    {
        $master = 'GRP_MASTER_1';

        // 3 siblings all with invoice_balance = 0, total_amount = 200
        foreach (['ROOM_A', 'ROOM_B', 'ROOM_C'] as $id) {
            $this->makeBooking([
                'beds24_booking_id'  => $id,
                'master_booking_id'  => $master,
                'booking_group_size' => 3,
                'total_amount'       => 200.00,
                'invoice_balance'    => 0,
            ]);
        }

        // The booking the cashier typed
        $booking = Beds24Booking::where('beds24_booking_id', 'ROOM_A')->first();

        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $beds24Svc->expects($this->never())->method($this->anything());

        $resolver = new GroupAwareCashierAmountResolver($beds24Svc);
        $result   = $resolver->resolve($booking);

        $this->assertFalse($result->isSingleBooking);
        $this->assertEquals(600.00, $result->usdAmount);  // 3 × 200
        $this->assertEquals($master, $result->effectiveMasterBookingId);
        $this->assertEquals(3, $result->groupSizeExpected);
        $this->assertEquals(3, $result->groupSizeLocal);
        $this->assertTrue($result->isGroupComplete);
    }

    /** @test */
    public function group_booking_does_not_double_count_the_entered_booking(): void
    {
        $master = 'GRP_MASTER_2';

        // Only 2 rooms — ROOM_X is both the entered booking and a sibling
        foreach (['ROOM_X', 'ROOM_Y'] as $id) {
            $this->makeBooking([
                'beds24_booking_id'  => $id,
                'master_booking_id'  => $master,
                'booking_group_size' => 2,
                'total_amount'       => 300.00,
                'invoice_balance'    => 0,
            ]);
        }

        $booking = Beds24Booking::where('beds24_booking_id', 'ROOM_X')->first();

        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $resolver  = new GroupAwareCashierAmountResolver($beds24Svc);
        $result    = $resolver->resolve($booking);

        // 2 rooms × 300 = 600 (ROOM_X counted once via sibling query)
        $this->assertEquals(600.00, $result->usdAmount);
        $this->assertEquals(2, $result->groupSizeLocal);
    }

    // -------------------------------------------------------------------------
    // (C) Group — invoice_balance used over total_amount when positive
    // -------------------------------------------------------------------------

    /** @test */
    public function group_sum_uses_invoice_balance_when_positive(): void
    {
        $master = 'GRP_BALANCE_TEST';

        // ROOM_P: partially paid (invoice_balance = 120, total = 300)
        // ROOM_Q: unpaid (invoice_balance = 0, total = 300) → effectiveUsdAmount = 300
        $this->makeBooking([
            'beds24_booking_id'  => 'ROOM_P',
            'master_booking_id'  => $master,
            'booking_group_size' => 2,
            'total_amount'       => 300.00,
            'invoice_balance'    => 120.00,
        ]);
        $this->makeBooking([
            'beds24_booking_id'  => 'ROOM_Q',
            'master_booking_id'  => $master,
            'booking_group_size' => 2,
            'total_amount'       => 300.00,
            'invoice_balance'    => 0,
        ]);

        $booking   = Beds24Booking::where('beds24_booking_id', 'ROOM_P')->first();
        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $resolver  = new GroupAwareCashierAmountResolver($beds24Svc);
        $result    = $resolver->resolve($booking);

        // 120 (balance) + 300 (total, balance=0) = 420
        $this->assertEquals(420.00, $result->usdAmount);
    }

    // -------------------------------------------------------------------------
    // (D) Group — missing siblings, API fetch succeeds
    // -------------------------------------------------------------------------

    /** @test */
    public function group_booking_fetches_missing_siblings_from_api_when_incomplete(): void
    {
        $master = 'GRP_INCOMPLETE_1';

        // Only 1 of 2 siblings stored locally
        $this->makeBooking([
            'beds24_booking_id'  => 'LOCAL_ONLY',
            'master_booking_id'  => $master,
            'booking_group_size' => 2,
            'total_amount'       => 250.00,
            'invoice_balance'    => 0,
            // raw data contains both sibling IDs
            'beds24_raw_data'    => [
                'booking' => [
                    'bookingGroup' => [
                        'master' => $master,
                        'ids'    => ['LOCAL_ONLY', 'MISSING_SIBLING'],
                    ],
                ],
            ],
        ]);

        $booking = Beds24Booking::where('beds24_booking_id', 'LOCAL_ONLY')->first();

        // Fake API response for the missing sibling
        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $beds24Svc->expects($this->once())
            ->method('getBooking')
            ->with('MISSING_SIBLING')
            ->willReturn(['data' => [[
                'booking'      => ['price' => 250],
                'invoiceItems' => [],
            ]]]);

        $resolver = new GroupAwareCashierAmountResolver($beds24Svc);
        $result   = $resolver->resolve($booking);

        $this->assertFalse($result->isSingleBooking);
        $this->assertEquals(500.00, $result->usdAmount);  // 250 + 250
        $this->assertEquals(2, $result->groupSizeExpected);
        $this->assertFalse($result->isGroupComplete);  // partial local sync
    }

    // -------------------------------------------------------------------------
    // (E) Group — missing siblings, API fetch throws
    // -------------------------------------------------------------------------

    /** @test */
    public function group_booking_throws_incomplete_sync_exception_when_api_fetch_fails(): void
    {
        $master = 'GRP_INCOMPLETE_2';

        $this->makeBooking([
            'beds24_booking_id'  => 'LOCAL_ONLY_2',
            'master_booking_id'  => $master,
            'booking_group_size' => 2,
            'total_amount'       => 200.00,
            'invoice_balance'    => 0,
            'beds24_raw_data'    => [
                'booking' => [
                    'bookingGroup' => [
                        'master' => $master,
                        'ids'    => ['LOCAL_ONLY_2', 'MISSING_2'],
                    ],
                ],
            ],
        ]);

        $booking = Beds24Booking::where('beds24_booking_id', 'LOCAL_ONLY_2')->first();

        $beds24Svc = $this->createMock(Beds24BookingService::class);
        $beds24Svc->method('getBooking')
            ->willThrowException(new \RuntimeException('Beds24 API unreachable'));

        $resolver = new GroupAwareCashierAmountResolver($beds24Svc);

        $this->expectException(\App\Exceptions\IncompleteGroupSyncException::class);
        $resolver->resolve($booking);
    }
}
