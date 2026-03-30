<?php

namespace Tests\Feature\Fx;

use App\Enums\CashTransactionSource;
use App\Models\Beds24Booking;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\Fx\WebhookReconciliationService;
use App\Services\OwnerAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for Beds24WebhookController::handleWebhookPayment() →
 *   createExternalBookkeepingRow() behaviour.
 *
 * We test via the HTTP layer because the methods are private — the controller
 * is the entry point and the assertions are on DB state.
 */
class ExternalBookkeepingRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Disable reconciliation for these tests — we're testing the external path
        config(['features.fx_webhook_reconciliation' => false]);
    }

    /** @test */
    public function cash_payment_without_ref_creates_beds24_external_row(): void
    {
        $booking = $this->makeBooking();
        $this->openShift();

        $this->postWebhookPayment($booking->beds24_booking_id, 100.0, 'naqd', 'Оплата', null, '999');

        $this->assertDatabaseHas('cash_transactions', [
            'beds24_booking_id' => $booking->beds24_booking_id,
            'source_trigger'    => CashTransactionSource::Beds24External->value,
            'amount'            => '100.00',
            'payment_method'    => 'naqd',
            'beds24_payment_ref' => 'b24_item_999',
        ]);
    }

    /** @test */
    public function non_cash_payment_creates_beds24_external_row_without_alert(): void
    {
        $booking = $this->makeBooking();
        $this->openShift();

        $alertMock = $this->mock(OwnerAlertService::class);
        $alertMock->shouldNotReceive('sendOpsAlert');

        $this->postWebhookPayment($booking->beds24_booking_id, 200.0, 'card', 'Card payment', null, '888');

        $this->assertDatabaseHas('cash_transactions', [
            'beds24_booking_id' => $booking->beds24_booking_id,
            'source_trigger'    => CashTransactionSource::Beds24External->value,
            'payment_method'    => 'card',
        ]);
    }

    /** @test */
    public function duplicate_by_beds24_item_id_is_skipped(): void
    {
        $booking = $this->makeBooking();
        $this->openShift();

        // First delivery
        $this->postWebhookPayment($booking->beds24_booking_id, 100.0, 'naqd', 'Оплата', null, '777');
        // Duplicate webhook delivery — same item ID
        $this->postWebhookPayment($booking->beds24_booking_id, 100.0, 'naqd', 'Оплата', null, '777');

        $this->assertDatabaseCount('cash_transactions', 1);
    }

    /** @test */
    public function external_rows_excluded_from_drawer_truth_scope(): void
    {
        $booking = $this->makeBooking();
        $this->openShift();

        $this->postWebhookPayment($booking->beds24_booking_id, 100.0, 'naqd', 'Оплата', null, '555');

        $this->assertDatabaseCount('cash_transactions', 1);

        // scopeDrawerTruth() must exclude this row
        $drawerCount = CashTransaction::drawerTruth()->count();
        $this->assertSame(0, $drawerCount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeBooking(): Beds24Booking
    {
        return Beds24Booking::factory()->create([
            'beds24_booking_id' => 'TEST-' . uniqid(),
            'guest_name'        => 'Test Guest',
        ]);
    }

    private function openShift(): CashierShift
    {
        $user = User::factory()->create();
        return CashierShift::factory()->create([
            'status'    => 'open',
            'opened_at' => now(),
            'opened_by' => $user->id,
        ]);
    }

    /**
     * Simulate a Beds24 v2 webhook payload with a single payment invoiceItem.
     */
    private function postWebhookPayment(
        string  $bookingId,
        float   $amount,
        string  $method,
        string  $description,
        ?string $ref,
        ?string $itemId,
    ): void {
        $payload = [
            'booking' => [
                'bookId'         => $bookingId,
                'invoice_balance' => 0,
                'total_amount'   => $amount,
                'invoiceItems'   => [
                    array_filter([
                        'id'          => $itemId,
                        'type'        => 'payment',
                        'amount'      => $amount,
                        'status'      => $method,
                        'description' => $description,
                        '_ref'        => $ref,
                    ], fn($v) => $v !== null),
                ],
            ],
        ];

        $this->postJson(route('beds24.webhook'), $payload);
    }
}
