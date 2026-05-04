<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by BotPaymentService::recordBulkGroupPayment when the group
 * composition (sibling list, total amount) at submit time differs from
 * the snapshot the operator was shown.
 *
 * Race-condition safety: between the moment the bot fetched the group
 * for the operator preview and the moment the operator tapped Confirm,
 * Beds24 may have:
 *   - added/removed a sibling
 *   - cancelled one
 *   - changed an invoice total
 *
 * Submit must revalidate against current Beds24Booking state. Mismatch
 * → reject and force operator to re-pick the group.
 */
class GroupCompositionChangedException extends \RuntimeException
{
    public function __construct(
        public readonly string $masterBookingId,
        public readonly array  $expectedSnapshot,    // what the operator confirmed
        public readonly array  $actualSnapshot,      // what the DB shows now
        string $message = 'Group composition changed since preview. Please reload and try again.',
    ) {
        parent::__construct($message);
    }
}
