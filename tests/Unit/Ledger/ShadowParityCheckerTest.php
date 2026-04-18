<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Actions\Ledger\Adapters\Beds24PaymentAdapter;
use App\Enums\CashTransactionSource;
use App\Enums\Currency;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Beds24Booking;
use App\Services\Ledger\ShadowParityChecker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShadowParityCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_matched_rows_report_zero_drift(): void
    {
        $this->seedMatchedPair('B1', 'i1', 100.00, 'cash');
        $this->seedMatchedPair('B2', 'i2', 50.00,  'card');

        $report = $this->check();

        $this->assertSame(2, $report->legacyCount);
        $this->assertSame(2, $report->ledgerCount);
        $this->assertSame(2, $report->matchedCount());
        $this->assertFalse($report->hasDrift());
        $this->assertSame(0, $report->driftCount());
        $this->assertSame(1.0, $report->matchRate());
    }

    public function test_legacy_without_ledger_counts_as_missing(): void
    {
        $this->seedLegacyOnly('B1', 'i1', 100.00, 'cash');

        $report = $this->check();

        $this->assertSame(1, $report->legacyCount);
        $this->assertSame(0, $report->ledgerCount);
        $this->assertSame(0, $report->matchedCount());
        $this->assertCount(1, $report->missingLedger);
        $this->assertSame('B1', $report->missingLedger[0]['booking_id']);
        $this->assertTrue($report->hasDrift());
    }

    public function test_ledger_without_legacy_counts_as_extra(): void
    {
        // Ledger-only: legacy dedup thought the row was already written
        // but we shadow-wrote anyway. Shadow mode should never produce
        // this, but the checker must detect it if it does.
        $this->seedLedgerOnly('B3', 'i3', 70.00, 'card');

        $report = $this->check();

        $this->assertSame(0, $report->legacyCount);
        $this->assertSame(1, $report->ledgerCount);
        $this->assertCount(1, $report->extraLedger);
        $this->assertSame('B3', $report->extraLedger[0]['booking_id']);
        $this->assertTrue($report->hasDrift());
    }

    public function test_amount_mismatch_is_classified(): void
    {
        $booking = $this->makeBooking('B4');
        $this->insertLegacy($booking, 100.00, 'cash', 'i4');
        $this->insertLedger($booking, 99.00,  'cash', 'i4');  // off by 1

        $report = $this->check();

        $this->assertCount(1, $report->amountMismatches);
        $this->assertSame('amount',      $report->amountMismatches[0]['reason']);
        $this->assertSame('100.00',      $report->amountMismatches[0]['legacy']['amount']);
        $this->assertSame('99.00',       $report->amountMismatches[0]['ledger']['amount']);
        $this->assertTrue($report->hasDrift());
    }

    public function test_method_mismatch_after_normalisation(): void
    {
        $booking = $this->makeBooking('B5');
        $this->insertLegacy($booking, 40.00, 'cash', 'i5');
        // Directly insert a ledger row with mismatched method —
        // bypasses the adapter mapping to simulate integrator drift.
        $this->insertLedger($booking, 40.00, 'bank', 'i5');  // 'bank' → BankTransfer; legacy normalises 'cash' → Cash

        $report = $this->check();

        $this->assertCount(1, $report->methodMismatches);
        $this->assertSame('cash',          $report->methodMismatches[0]['legacy']['normalised']);
        $this->assertSame('bank_transfer', $report->methodMismatches[0]['ledger']['payment_method']);
    }

    public function test_multilingual_legacy_methods_match_ledger_mapping(): void
    {
        $booking = $this->makeBooking('B6');
        $this->insertLegacy($booking, 25.00, 'naqd',      'i6a');
        $this->insertLegacy($booking, 25.00, 'наличные',  'i6b');
        $this->insertLedger($booking, 25.00, 'cash',      'i6a');
        $this->insertLedger($booking, 25.00, 'cash',      'i6b');

        $report = $this->check();

        $this->assertSame(2, $report->matchedCount());
        $this->assertCount(0, $report->methodMismatches);
    }

    public function test_null_item_ref_rows_are_unmatchable(): void
    {
        $booking = $this->makeBooking('B7');
        $this->insertLegacy($booking, 80.00, 'cash', null);

        $report = $this->check();

        $this->assertCount(0, $report->matchedKeys);
        $this->assertCount(0, $report->missingLedger);
        $this->assertCount(1, $report->unmatchableRows);
        $this->assertSame('legacy_no_item_ref', $report->unmatchableRows[0]['marker']);
        // Unmatchable rows are drift-neutral by design: ops must review.
        $this->assertFalse($report->hasDrift());
    }

    public function test_unknown_source_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ShadowParityChecker::class)->check(
            Carbon::now()->subDay(),
            Carbon::now(),
            'unknown_source'
        );
    }

    // ---------------------------------------------------------------------

    private function check(): \App\DTOs\Ledger\ShadowParityReport
    {
        return app(ShadowParityChecker::class)->check(
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
            'beds24'
        );
    }

    private function seedMatchedPair(string $bookingId, string $itemId, float $amount, string $method): void
    {
        $booking = $this->makeBooking($bookingId);
        $this->insertLegacy($booking, $amount, $method, $itemId);
        $this->insertLedger($booking, $amount, $method, $itemId);
    }

    private function seedLegacyOnly(string $bookingId, string $itemId, float $amount, string $method): void
    {
        $booking = $this->makeBooking($bookingId);
        $this->insertLegacy($booking, $amount, $method, $itemId);
    }

    private function seedLedgerOnly(string $bookingId, string $itemId, float $amount, string $method): void
    {
        $booking = $this->makeBooking($bookingId);
        $this->insertLedger($booking, $amount, $method, $itemId);
    }

    private function makeBooking(string $beds24BookingId): Beds24Booking
    {
        return Beds24Booking::create([
            'beds24_booking_id' => $beds24BookingId,
            'property_id'       => 1,
            'room_id'           => 1,
            'room_name'         => 'Room',
            'arrival_date'      => now()->addDay(),
            'departure_date'    => now()->addDays(2),
            'guest_name'        => 'Guest ' . $beds24BookingId,
            'total_amount'      => 200.00,
            'invoice_balance'   => 0,
            'currency'          => 'USD',
            'booking_status'    => 'confirmed',
        ]);
    }

    private function insertLegacy(Beds24Booking $booking, float $amount, string $method, ?string $itemId): void
    {
        DB::table('cash_transactions')->insert([
            'type'               => TransactionType::IN->value,
            'amount'             => $amount,
            'currency'           => 'USD',
            'category'           => TransactionCategory::SALE->value,
            'source_trigger'     => CashTransactionSource::Beds24External->value,
            'beds24_booking_id'  => $booking->beds24_booking_id,
            'beds24_payment_ref' => $itemId !== null ? "b24_item_{$itemId}" : null,
            'payment_method'     => $method,
            'guest_name'         => $booking->guest_name,
            'room_number'        => $booking->room_name,
            'reference'          => $itemId !== null ? "Beds24 #{$booking->beds24_booking_id} item#{$itemId}" : null,
            'notes'              => "test payment ({$method})",
            'occurred_at'        => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function insertLedger(Beds24Booking $booking, float $amount, string $method, ?string $itemId): void
    {
        // Build via the adapter so payment_method is mapped correctly
        // for the match-path tests. Method mismatch tests explicitly
        // pass a method string that would normalise to a different
        // PaymentMethod than legacy.
        app(Beds24PaymentAdapter::class)->record(
            beds24BookingId:     $booking->beds24_booking_id,
            beds24ItemId:        $itemId,
            amount:              $amount,
            currency:            Currency::USD,
            beds24PaymentMethod: $method,
            guestName:           $booking->guest_name,
            roomNumber:          $booking->room_name,
        );
    }
}
