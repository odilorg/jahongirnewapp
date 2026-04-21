<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

/**
 * Handles "guide" and "guide_*" callbacks from @j_cashier_bot.
 *
 * Pure extraction from CashierBotController::showGuide + ::showGuideTopic.
 * Pure string + keyboard formatter — no DB, no session mutation, no
 * external calls. Returned reply shape is:
 *
 *   ['text' => string, 'kb' => array|null, 'type' => 'inline']
 *
 * The router calls $this->send() with that shape. Telegram envelope I/O
 * stays on the controller per the plan's Option-A seam.
 *
 * Behaviour must be byte-identical — tests pin that.
 */
final class ShowGuideAction
{
    /**
     * @param string|null $topic null → main guide menu; otherwise the topic key
     *                           from the button's callback_data after "guide_".
     *
     * @return array{text: string, kb: array, type: string}
     */
    public function execute(?string $topic = null): array
    {
        if ($topic === null || $topic === '') {
            return $this->mainMenu();
        }

        return $this->topic($topic);
    }

    /**
     * @return array{text: string, kb: array, type: string}
     */
    private function mainMenu(): array
    {
        return [
            'text' => "📖 <b>Инструкция — Кассир Бот</b>\n\nВыберите раздел:",
            'kb'   => ['inline_keyboard' => [
                [['text' => '💵 Оплата гостя',   'callback_data' => 'guide_payment'],  ['text' => '📤 Расходы',          'callback_data' => 'guide_expense']],
                [['text' => '🔄 Обмен валюты',  'callback_data' => 'guide_exchange'], ['text' => '💰 Баланс',            'callback_data' => 'guide_balance']],
                [['text' => '🟢 Открытие смены', 'callback_data' => 'guide_open'],     ['text' => '🔒 Закрытие смены',    'callback_data' => 'guide_close']],
                [['text' => '💡 Советы и правила', 'callback_data' => 'guide_tips']],
                [['text' => '« Назад в меню',   'callback_data' => 'menu']],
            ]],
            'type' => 'inline',
        ];
    }

    /**
     * @return array{text: string, kb: array, type: string}
     */
    private function topic(string $topic): array
    {
        $content = match ($topic) {
            'payment' =>
                "💵 <b>Оплата гостя</b>\n\n"
                . "1. Нажмите «💵 Оплата»\n"
                . "2. Выберите гостя из списка заездов\n"
                . "3. Или нажмите ✏️ Ручной ввод и введите номер брони\n"
                . "4. Введите сумму (например: <code>500000</code>)\n"
                . "5. Выберите валюту (UZS/USD/EUR)\n"
                . "6. Выберите способ оплаты (нал/карта/перевод)\n"
                . "7. Подтвердите ✅\n\n"
                . "💡 <b>Форматы суммы:</b>\n"
                . "• <code>500000</code> — простое число\n"
                . "• <code>500 000</code> — с пробелом\n"
                . "• <code>$100</code> или <code>100$</code> — автоматически USD\n"
                . "• <code>100 долларов</code> — автоматически USD",
            'expense' =>
                "📤 <b>Расходы</b>\n\n"
                . "1. Нажмите «📤 Расход»\n"
                . "2. Выберите категорию:\n"
                . "   🛒 Хозтовары, 🍽️ Еда, 🔧 Ремонт,\n"
                . "   🚕 Транспорт, 👕 Прачечная и др.\n"
                . "3. Введите сумму\n"
                . "4. Опишите расход (например: «Моющее средство»)\n"
                . "5. Подтвердите ✅\n\n"
                . "💡 <b>Умный ввод суммы:</b>\n"
                . "• <code>50000</code> — автоматически UZS\n"
                . "• <code>$20</code> или <code>20$</code> — автоматически USD\n"
                . "• <code>€15</code> — автоматически EUR\n"
                . "• <code>20 долларов</code> — автоматически USD",
            'exchange' =>
                "🔄 <b>Обмен валюты</b>\n\n"
                . "<b>Пример:</b> Гость даёт $100, вы даёте 1,280,000 сум\n\n"
                . "1. Нажмите «🔄 Обмен»\n"
                . "2. Выберите валюту ПРИЁМА → USD\n"
                . "3. Введите сумму приёма → <code>100</code>\n"
                . "4. Выберите валюту ВЫДАЧИ → UZS\n"
                . "5. Введите сумму выдачи → <code>1280000</code>\n"
                . "6. Подтвердите ✅\n\n"
                . "📌 Бот создаст 2 записи:\n"
                . "• 📥 +100 USD (приход)\n"
                . "• 📤 -1,280,000 UZS (расход)",
            'balance' =>
                "💰 <b>Баланс</b>\n\n"
                . "Нажмите «💰 Баланс» чтобы увидеть:\n\n"
                . "• Текущий баланс по каждой валюте\n"
                . "• Общий приход\n"
                . "• Общий расход\n"
                . "• Количество транзакций\n\n"
                . "💡 Проверяйте баланс 2-3 раза в день!",
            'open' =>
                "🟢 <b>Открытие смены</b>\n\n"
                . "1. Нажмите «Открыть смену»\n"
                . "2. Бот подтвердит открытие\n"
                . "3. Появится главное меню с кнопками\n\n"
                . "⚠️ <b>Правила:</b>\n"
                . "• Открывайте смену в начале рабочего дня\n"
                . "• Только одна смена может быть открыта\n"
                . "• Если прошлая не закрыта — закройте сначала",
            'close' =>
                "🔒 <b>Закрытие смены</b>\n\n"
                . "1. Нажмите «🔒 Закрыть смену»\n"
                . "2. Бот попросит пересчитать кассу\n"
                . "3. Введите фактическую сумму по каждой валюте\n"
                . "4. Бот сравнит с системой:\n"
                . "   ✅ Совпадает — «Отлично!»\n"
                . "   ⚠️ Разница — покажет расхождение\n\n"
                . "⚠️ <b>Важно:</b>\n"
                . "• ОБЯЗАТЕЛЬНО закрывайте смену!\n"
                . "• Считайте деньги внимательно\n"
                . "• О большом расхождении — сообщите менеджеру",
            'tips' =>
                "💡 <b>Советы и правила</b>\n\n"
                . "1️⃣ Записывайте КАЖДУЮ операцию СРАЗУ\n\n"
                . "2️⃣ Добавляйте заметки — «Гость #205», «Моющее»\n\n"
                . "3️⃣ Проверяйте баланс 2-3 раза в день\n\n"
                . "4️⃣ При закрытии — считайте деньги дважды\n\n"
                . "5️⃣ Ошиблись? Сразу сообщите менеджеру\n\n"
                . "6️⃣ Не давайте телефон посторонним\n\n"
                . "❓ Проблемы? Обратитесь к менеджеру",
            default => "Раздел не найден.",
        };

        return [
            'text' => $content,
            'kb'   => ['inline_keyboard' => [
                [['text' => '« К инструкции', 'callback_data' => 'guide']],
            ]],
            'type' => 'inline',
        ];
    }
}
