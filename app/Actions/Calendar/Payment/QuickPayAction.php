<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Payment;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

/**
 * Record a supplier payment (driver / guide / accommodation) from the
 * slide-over's per-row "Pay" button.
 *
 * Business rules centralised here:
 *   - required fields: supplier_type, supplier_id, amount > 0
 *   - supplier_type must be one of driver|guide|accommodation
 *   - inquiry is auto-claimed by the operator if unowned
 *   - currency locked to USD (calendar UX is single-currency)
 *   - payment_date = today
 *
 * Note — payMethod stays in the page for now (cash default) because
 * there is no runtime selector in the Quick-Pay popover yet; when added,
 * extend the $data contract rather than adding more properties.
 */
final class QuickPayAction
{
    private const ALLOWED_TYPES = ['driver', 'guide', 'accommodation'];

    /**
     * @param  array{
     *   supplier_type: ?string,
     *   supplier_id: ?int,
     *   amount: ?string,
     *   method?: string,
     *   operator_id: int
     * } $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        $type   = $data['supplier_type'] ?? null;
        $id     = $data['supplier_id']   ?? null;
        $amount = $data['amount']        ?? null;
        $method = $data['method'] ?? 'cash';

        if (! $type || ! $id || ! $amount) {
            return CalendarActionResult::failure('Payment failed — missing data');
        }

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return CalendarActionResult::failure('Payment failed — invalid supplier type');
        }

        if ((float) $amount <= 0) {
            return CalendarActionResult::failure('Payment failed — amount must be positive');
        }

        return DB::transaction(function () use ($inquiry, $type, $id, $amount, $method, $data): CalendarActionResult {
            $inquiry->assignIfUnowned($data['operator_id']);

            SupplierPayment::create([
                'supplier_type'      => $type,
                'supplier_id'        => $id,
                'booking_inquiry_id' => $inquiry->id,
                'amount'             => (float) $amount,
                'currency'           => 'USD',
                'payment_date'       => now()->toDateString(),
                'payment_method'     => $method,
                'status'             => 'recorded',
            ]);

            $name = $this->resolveSupplierName($inquiry, $type);

            return CalendarActionResult::success("Paid \${$amount} to {$name}");
        });
    }

    private function resolveSupplierName(BookingInquiry $inquiry, string $type): string
    {
        return match ($type) {
            'driver'        => $inquiry->driver?->full_name ?? 'driver',
            'guide'         => $inquiry->guide?->full_name ?? 'guide',
            'accommodation' => $inquiry->stays->first()?->accommodation?->name ?? 'accommodation',
            default         => 'supplier',
        };
    }
}
