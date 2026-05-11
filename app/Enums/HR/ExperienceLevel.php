<?php

declare(strict_types=1);

namespace App\Enums\HR;

/**
 * Self-reported total work experience on the public application form.
 * Kept coarse — same reasoning as LanguageLevel.
 */
enum ExperienceLevel: string
{
    case None = 'none';
    case LessThan1 = 'less_than_1y';
    case OneToThree = '1_to_3y';
    case MoreThan3 = 'more_than_3y';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Нет опыта',
            self::LessThan1 => 'Меньше 1 года',
            self::OneToThree => '1–3 года',
            self::MoreThan3 => 'Больше 3 лет',
        };
    }

    /**
     * @return array<string, string>
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
