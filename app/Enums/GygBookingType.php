<?php

namespace App\Enums;

/**
 * Represents the booking type for a GetYourGuide activity.
 *
 * Group  — fixed meeting point (Gur Emir Mausoleum), no hotel pickup.
 * Private — hotel pickup required; reminder falls back to "your hotel" until
 *           guest supplies the hotel name.
 * Unknown — type could not be determined from available signals; treated
 *           conservatively as Private so we never send a wrong address.
 */
enum GygBookingType: string
{
    case Group   = 'group';
    case Private = 'private';
    case Unknown = 'unknown';

    public static function fromParsed(?string $value): self
    {
        return match ($value) {
            'group'   => self::Group,
            'private' => self::Private,
            default   => self::Unknown,
        };
    }

    public function isGroup(): bool
    {
        return $this === self::Group;
    }

    public function isPrivate(): bool
    {
        return $this === self::Private || $this === self::Unknown;
    }
}
