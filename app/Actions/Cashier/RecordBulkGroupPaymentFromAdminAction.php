<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Models\Beds24Booking;
use App\Models\CashierShift;
use App\Services\BotPaymentService;

/**
 * Phase 1.7.3 — Admin Bulk Group Payment.
 *
 * Bridges Filament form input → BotPaymentService::recordBulkGroupPayment.
 * Builds the expected snapshot from the current Beds24Booking state,
 * which the service then revalidates inside its transaction (race-safe).
 */
class RecordBulkGroupPaymentFromAdminAction
{
    public function __construct(
        private BotPaymentService $botPaymentService,
    ) {}

    /**
     * @param array{
     *   cashier_shift_id: int,
     *   master_booking_id: string,
     *   total_currency: string,
     *   total_amount: float,
     *   payment_method: string,
     * } $data
     *
     * @return array{
     *   journal_uuid: string,
     *   transactions: array<\App\Models\CashTransaction>,
     *   master_booking_id: string,
     * }
     */
    public function execute(array $data): array
    {
        $shift = CashierShift::findOrFail((int) $data['cashier_shift_id']);
        if (! $shift->isOpen()) {
            throw new \InvalidArgumentException("Shift #{$shift->id} is not open.");
        }

        $b = Beds24Booking::where('beds24_booking_id', $data['master_booking_id'])->firstOrFail();
        $master = (string) ($b->master_booking_id ?? $b->beds24_booking_id);

        // Build the operator-facing snapshot — what the form just showed the
        // operator. The service revalidates this against the current DB state
        // inside its transaction; mismatch → GroupCompositionChangedException.
        $expectedSnapshot = Beds24Booking::where('master_booking_id', $master)
            ->orderBy('beds24_booking_id')
            ->get(['beds24_booking_id', 'total_amount'])
            ->map(fn ($s) => [
                'booking_id'    => (string) $s->beds24_booking_id,
                'invoice_total' => (float)  $s->total_amount,
            ])
            ->all();

        $result = $this->botPaymentService->recordBulkGroupPayment(
            masterBookingId:  $master,
            totalCurrency:    (string) $data['total_currency'],
            totalAmount:      (float)  $data['total_amount'],
            paymentMethod:    (string) $data['payment_method'],
            shiftId:          $shift->id,
            cashierId:        (int) ($shift->user_id ?? auth()->id()),
            expectedSnapshot: $expectedSnapshot,
        );

        return [
            'journal_uuid'      => $result['journal_uuid'],
            'transactions'      => $result['transactions'],
            'master_booking_id' => $master,
        ];
    }
}
