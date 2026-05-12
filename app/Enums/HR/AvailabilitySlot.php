<?php

declare(strict_types=1);

namespace App\Enums\HR;

/**
 * Time-of-day availability slots a candidate can pick on the public
 * application form. Multi-select via checkboxes — candidates pick
 * every slot during which they can actually work.
 *
 * Use case: many young candidates attend classes 8:00–13:00 and are
 * only free in the afternoon/evening; HR needs to schedule shifts
 * around that AND call them outside class hours.
 *
 * Phase 1.1, 2026-05-11.
 */
enum AvailabilitySlot: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    case Night = 'night';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Утро (8:00–13:00)',
            self::Afternoon => 'День (13:00–18:00)',
            self::Evening => 'Вечер (18:00–22:00)',
            self::Night => 'Ночь (22:00–8:00)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Morning => 'Утро',
            self::Afternoon => 'День',
            self::Evening => 'Вечер',
            self::Night => 'Ночь',
        };
    }

    /**
     * @return array<string, string> value => Russian label (with hour range)
     */
    public static function publicOptions(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
