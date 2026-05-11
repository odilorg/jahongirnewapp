<?php

declare(strict_types=1);

namespace App\Enums\HR;

/**
 * Job positions accepted by the public application form
 * (`/jobs/apply`). Phase 1 list confirmed 2026-05-11.
 *
 * Each case has:
 *  - a Russian label for the public form dropdown (matches OLX
 *    listing language)
 *  - a key for the single position-specific question stored in
 *    `job_candidates.position_answers` JSON
 *
 * Adding a new position: add the case, the label, the question key,
 * and the role-specific question Blade partial. No migration needed.
 */
enum Position: string
{
    case HotelAdmin = 'hotel_admin';
    case Kitchen = 'kitchen';
    case Housekeeping = 'housekeeping';
    case Waiter = 'waiter';
    case Cashier = 'cashier';
    case Driver = 'driver';
    case Guide = 'guide';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HotelAdmin => 'Администратор / Ресепшн',
            self::Kitchen => 'Кухня',
            self::Housekeeping => 'Горничная / Уборка',
            self::Waiter => 'Официант / Официантка',
            self::Cashier => 'Кассир',
            self::Driver => 'Водитель',
            self::Guide => 'Гид',
            self::Other => 'Другое',
        };
    }

    /**
     * Russian text for the position-specific question shown on the
     * public form. Form Blade reads this via a `match`/switch on the
     * selected position; submitted answer is stored under the same
     * key inside `position_answers` JSON.
     */
    public function specificQuestion(): string
    {
        return match ($this) {
            self::HotelAdmin => 'Работали ли вы раньше в отеле или на ресепшн?',
            self::Kitchen => 'Какую работу на кухне вы можете выполнять?',
            self::Housekeeping => 'Убирали ли вы раньше номера в отеле?',
            self::Waiter => 'Работали ли вы раньше официантом?',
            self::Cashier => 'Работали ли вы раньше с наличными или POS-терминалом?',
            self::Driver => 'Есть ли у вас собственный автомобиль?',
            self::Guide => 'Есть ли у вас лицензия гида?',
            self::Other => 'На какую должность вы претендуете?',
        };
    }

    /**
     * Shape of the answer accepted for each position. Used by both
     * the Blade form (renders correct widget) and the FormRequest
     * (validates correct shape).
     *
     * @return array{type: string, options?: array<string,string>}
     */
    public function answerSchema(): array
    {
        return match ($this) {
            self::Kitchen => [
                'type' => 'select',
                'options' => [
                    'cook' => 'Повар',
                    'assistant_cook' => 'Помощник повара',
                    'dishwasher' => 'Посудомойщик',
                    'prep' => 'Заготовщик',
                    'other' => 'Другое',
                ],
            ],
            self::Other => [
                'type' => 'text', // free-form short answer
            ],
            default => [
                'type' => 'yes_no',
                'options' => [
                    'yes' => 'Да',
                    'no' => 'Нет',
                ],
            ],
        };
    }

    /**
     * @return array<string, string> case value => Russian label
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
