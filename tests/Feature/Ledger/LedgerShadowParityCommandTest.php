<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Actions\Ledger\Adapters\Beds24PaymentAdapter;
use App\Enums\CashTransactionSource;
use App\Enums\Currency;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Beds24Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerShadowParityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_returns_success_on_zero_drift(): void
    {
        $booking = $this->makeBooking('B10');
        $this->insertLegacy($booking, 100.00, 'cash', 'i10');
        app(Beds24PaymentAdapter::class)->record(
            beds24BookingId:     'B10',
            beds24ItemId:        'i10',
            amount:              100.00,
            currency:            Currency::USD,
            beds24PaymentMethod: 'cash',
            guestName:           $booking->guest_name,
        );

        $this->artisan('ledger:shadow-parity', ['--daily' => true])
            ->expectsOutputToContain('Ledger shadow parity report')
            ->expectsOutputToContain('Matched:                         1')
            ->expectsOutputToContain('Total drift:                     0')
            ->assertSuccessful();
    }

    public function test_command_fails_with_exit_1_on_drift(): void
    {
        $booking = $this->makeBooking('B11');
        // Legacy row without a ledger counterpart → missing_ledger drift.
        $this->insertLegacy($booking, 60.00, 'cash', 'i11');

        $this->artisan('ledger:shadow-parity', ['--daily' => true])
            ->expectsOutputToContain('Missing from ledger:             1')
            ->expectsOutputToContain('Total drift:                     1')
            ->assertFailed();
    }

    public function test_no_exit_on_drift_flag_keeps_success(): void
    {
        $booking = $this->makeBooking('B12');
        $this->insertLegacy($booking, 60.00, 'cash', 'i12');

        $this->artisan('ledger:shadow-parity', [
            '--daily'              => true,
            '--no-exit-on-drift'   => true,
        ])
            ->expectsOutputToContain('Total drift:                     1')
            ->assertSuccessful();
    }

    public function test_detailed_mode_prints_per_row_drift(): void
    {
        $booking = $this->makeBooking('B13');
        $this->insertLegacy($booking, 60.00, 'cash', 'i13');  // no ledger twin

        $this->artisan('ledger:shadow-parity', [
            '--daily'    => true,
            '--detailed' => true,
        ])
            ->expectsOutputToContain('[MISSING_LEDGER]')
            ->expectsOutputToContain('--- DETAILS ---')
            ->assertFailed();
    }

    public function test_unknown_source_returns_exit_2(): void
    {
        $this->artisan('ledger:shadow-parity', ['--source' => 'martian'])
            ->assertExitCode(2);
    }

    // ---------------------------------------------------------------------

    private function makeBooking(string $bookingId): Beds24Booking
    {
        return Beds24Booking::create([
            'beds24_booking_id' => $bookingId,
            'property_id'       => 1,
            'room_id'           => 1,
            'room_name'         => 'Room',
            'arrival_date'      => now()->addDay(),
            'departure_date'    => now()->addDays(2),
            'guest_name'        => 'Guest ' . $bookingId,
            'total_amount'      => 200.00,
            'invoice_balance'   => 0,
            'currency'          => 'USD',
            'booking_status'    => 'confirmed',
        ]);
    }

    private function insertLegacy(Beds24Booking $booking, float $amount, string $method, string $itemId): void
    {
        DB::table('cash_transactions')->insert([
            'type'               => TransactionType::IN->value,
            'amount'             => $amount,
            'currency'           => 'USD',
            'category'           => TransactionCategory::SALE->value,
            'source_trigger'     => CashTransactionSource::Beds24External->value,
            'beds24_booking_id'  => $booking->beds24_booking_id,
            'beds24_payment_ref' => "b24_item_{$itemId}",
            'payment_method'     => $method,
            'guest_name'         => $booking->guest_name,
            'reference'          => "Beds24 #{$booking->beds24_booking_id} item#{$itemId}",
            'notes'              => "test payment ({$method})",
            'occurred_at'        => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}
