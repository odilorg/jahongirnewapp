<?php

declare(strict_types=1);

namespace App\Actions\BookingBot;

use App\DTO\BotBookingRequestData;
use App\DTO\ResolvedBotBookingChargeData;

/**
 * Pure translator: (booking input + resolved charge) → Beds24 single-booking
 * payload array. No DB, no HTTP, no business rules.
 *
 * The shape of `invoiceItems` is the one unverified Beds24-side assumption
 * in this feature — keep the knowledge in this one class so a staging
 * verification surprise (wrong key name / wrong nesting) requires a fix
 * here only, not anywhere else.
 */
final class BuildBeds24BookingPayloadAction
{
    public function execute(
        BotBookingRequestData $data,
        ResolvedBotBookingChargeData $charge,
        string $notes,
    ): array {
        $payload = [
            'propertyId' => (int) $data->propertyId,
            'roomId'     => (int) $data->roomId,
            'arrival'    => $data->arrival,
            'departure'  => $data->departure,
            'firstName'  => $data->firstName,
            'lastName'   => $data->lastName,
            'email'      => $data->email ?? '',
            'mobile'     => $data->mobile ?? '',
            'numAdult'   => $data->numAdult,
            'numChild'   => $data->numChild,
            'status'     => 'confirmed',
            'notes'      => $notes,
        ];

        if ($charge->hasCharge) {
            $payload['invoiceItems'] = [[
                'type'        => 'charge',
                'description' => $charge->description,
                'qty'         => $charge->nights,
                'amount'      => $charge->pricePerNight,
            ]];
        }

        return $payload;
    }
}
