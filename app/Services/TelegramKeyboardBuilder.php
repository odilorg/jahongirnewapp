<?php

namespace App\Services;

class TelegramKeyboardBuilder
{
    /**
     * Build phone request keyboard
     */
    public function phoneRequestKeyboard(string $language = 'en'): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => __('telegram_pos.share_contact', [], $language),
                        'request_contact' => true,
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * Build main menu keyboard
     */
    public function mainMenuKeyboard(string $language = 'en', ?\App\Models\User $user = null): array
    {
        $buttons = [
            [
                ['text' => __('telegram_pos.start_shift', [], $language)],
                ['text' => __('telegram_pos.my_shift', [], $language)],
            ],
            [
                ['text' => __('telegram_pos.record_transaction', [], $language)],
                ['text' => __('telegram_pos.close_shift', [], $language)],
            ],
        ];

        // Add Reports button for managers
        if ($user && $user->hasAnyRole(['manager', 'super_admin'])) {
            $buttons[] = [
                ['text' => __('telegram_pos.reports', [], $language)],
            ];
        }

        $buttons[] = [
            ['text' => __('telegram_pos.help', [], $language)],
            ['text' => __('telegram_pos.settings', [], $language)],
        ];

        return [
            'keyboard' => $buttons,
            'resize_keyboard' => true,
        ];
    }

    /**
     * Build language selection inline keyboard
     */
    public function languageSelectionKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang:en'],
                    ['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru'],
                ],
                [
                    ['text' => '🇺🇿 O\'zbekcha', 'callback_data' => 'lang:uz'],
                ],
            ],
        ];
    }

    /**
     * Build cancel keyboard
     */
    public function cancelKeyboard(string $language = 'en'): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => __('telegram_pos.cancel', [], $language)],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * Build confirmation keyboard
     */
    public function confirmationKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => __('telegram_pos.confirm', [], $language), 'callback_data' => 'confirm:yes'],
                    ['text' => __('telegram_pos.cancel', [], $language), 'callback_data' => 'confirm:no'],
                ],
            ],
        ];
    }

    /**
     * Build transaction type selection keyboard
     */
    public function transactionTypeKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => __('telegram_pos.cash_in', [], $language), 'callback_data' => 'txn_type:in'],
                ],
                [
                    ['text' => __('telegram_pos.cash_out', [], $language), 'callback_data' => 'txn_type:out'],
                ],
                [
                    ['text' => __('telegram_pos.complex_transaction', [], $language), 'callback_data' => 'txn_type:in_out'],
                ],
                [
                    ['text' => __('telegram_pos.cancel', [], $language), 'callback_data' => 'txn_type:cancel'],
                ],
            ],
        ];
    }

    /**
     * Build currency selection keyboard
     */
    public function currencySelectionKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => 'UZS 🇺🇿', 'callback_data' => 'currency:UZS'],
                    ['text' => 'USD 🇺🇸', 'callback_data' => 'currency:USD'],
                ],
                [
                    ['text' => 'EUR 🇪🇺', 'callback_data' => 'currency:EUR'],
                    ['text' => 'RUB 🇷🇺', 'callback_data' => 'currency:RUB'],
                ],
                [
                    ['text' => __('telegram_pos.cancel', [], $language), 'callback_data' => 'currency:cancel'],
                ],
            ],
        ];
    }

    /**
     * Build category selection keyboard
     */
    public function categorySelectionKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => __('telegram_pos.category_sale', [], $language), 'callback_data' => 'category:sale'],
                    ['text' => __('telegram_pos.category_refund', [], $language), 'callback_data' => 'category:refund'],
                ],
                [
                    ['text' => __('telegram_pos.category_expense', [], $language), 'callback_data' => 'category:expense'],
                    ['text' => __('telegram_pos.category_deposit', [], $language), 'callback_data' => 'category:deposit'],
                ],
                [
                    ['text' => __('telegram_pos.category_change', [], $language), 'callback_data' => 'category:change'],
                    ['text' => __('telegram_pos.category_other', [], $language), 'callback_data' => 'category:other'],
                ],
                [
                    ['text' => __('telegram_pos.cancel', [], $language), 'callback_data' => 'category:cancel'],
                ],
            ],
        ];
    }

    /**
     * Build skip notes keyboard
     */
    public function skipNotesKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => __('telegram_pos.skip_notes', [], $language), 'callback_data' => 'notes:skip'],
                ],
            ],
        ];
    }

    /**
     * Build back to main menu keyboard
     */
    public function backToMainMenuKeyboard(string $language = 'en'): array
    {
        return [
            'keyboard' => [
                [
                    ['text' => __('telegram_pos.back', [], $language)],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * Remove keyboard
     */
    public function removeKeyboard(): array
    {
        return [
            'remove_keyboard' => true,
        ];
    }

    /**
     * Build manager reports menu keyboard
     */
    public function managerReportsKeyboard(string $language = 'en'): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 ' . __('telegram_pos.today_summary', [], $language), 'callback_data' => 'report:today'],
                ],
                [
                    ['text' => '💰 ' . __('telegram_pos.financial_range', [], $language), 'callback_data' => 'report:financial_range'],
                ],
                [
                    ['text' => '⚠️ ' . __('telegram_pos.discrepancies', [], $language), 'callback_data' => 'report:discrepancies'],
                ],
                [
                    ['text' => '📈 ' . __('telegram_pos.executive_dashboard', [], $language), 'callback_data' => 'report:executive'],
                ],
                [
                    ['text' => '💱 ' . __('telegram_pos.currency_exchange', [], $language), 'callback_data' => 'report:currency_exchange'],
                ],
                [
                    ['text' => '👥 ' . __('telegram_pos.shift_performance', [], $language), 'callback_data' => 'report:shifts'],
                ],
                [
                    ['text' => '💼 ' . __('telegram_pos.transaction_report', [], $language), 'callback_data' => 'report:transactions'],
                ],
                [
                    ['text' => '🏢 ' . __('telegram_pos.multi_location', [], $language), 'callback_data' => 'report:locations'],
                ],
                [
                    ['text' => '« ' . __('telegram_pos.back', [], $language), 'callback_data' => 'report:back'],
                ],
            ],
        ];
    }
}
