<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by BotPaymentService::recordBulkGroupPayment when at least
 * one sibling in the requested group already has a cashier_bot or
 * beds24_external payment recorded against it.
 *
 * v1 doctrine: bulk = ALL siblings or NONE. Subset/partial group
 * settlement is intentionally Phase 2.x territory because it requires
 * receivable allocation + per-room debt state + partial settlement
 * governance.
 *
 * Caller (bot/admin) should surface the list of already-paid siblings
 * so the operator can finish per-room manually instead.
 */
class GroupAlreadyPartiallyPaidException extends \RuntimeException
{
    public function __construct(
        public readonly string $masterBookingId,
        public readonly array  $alreadyPaidBookingIds,    // list of beds24_booking_id strings
        public readonly array  $unpaidBookingIds,
        string $message = 'Cannot bulk-settle: at least one sibling already has a recorded payment.',
    ) {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return [
            'master_booking_id'         => $this->masterBookingId,
            'already_paid_booking_ids'  => $this->alreadyPaidBookingIds,
            'unpaid_booking_ids'        => $this->unpaidBookingIds,
        ];
    }
}
