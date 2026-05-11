<?php

declare(strict_types=1);

namespace App\Enums\HR;

/**
 * Self-reported language proficiency on the public application form.
 *
 * Same four-level scale for Uzbek, Russian, English. Kept deliberately
 * coarse — the form is 2-3 minutes, candidates don't pick CEFR levels.
 * HR can probe deeper during the phone screen.
 */
enum LanguageLevel: string
{
    case No = 'no';
    case Basic = 'basic';
    case Good = 'good';
    case Fluent = 'fluent';

    public function label(): string
    {
        return match ($this) {
            self::No => 'Не знаю',
            self::Basic => 'Базовый',
            self::Good => 'Хороший',
            self::Fluent => 'Свободный',
        };
    }

    /**
     * @return array<string, string> value => Russian label
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
