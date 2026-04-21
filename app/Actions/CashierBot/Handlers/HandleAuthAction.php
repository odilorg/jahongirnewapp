<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Models\TelegramPosSession;
use App\Models\User;

/**
 * Handles the phone-contact auth step from @j_cashier_bot.
 *
 * Pure extraction from CashierBotController::handleAuth. User matching is
 * by the last nine digits of the shared phone number — preserved verbatim
 * because some users have duplicated country-code prefixes in the
 * telegram contact vs. the users table.
 *
 * Note: this Action deliberately does NOT rewrite User.telegram_user_id.
 * That field is shared with the POS bot; overwriting it would silently
 * break the other bot's session.
 *
 * Return shape lets the router decide how to proceed:
 *   ok=false → send the error reply and stop.
 *   ok=true  → send the welcome reply, then hand off to the main-menu
 *              flow (which sends two more messages of its own).
 *
 * @phpstan-type Reply array{text: string, kb?: array, type?: string}
 */
final class HandleAuthAction
{
    /**
     * @return array{ok: false, reply: array}|array{ok: true, reply: array, session: TelegramPosSession}
     */
    public function execute(int $chatId, array $contact): array
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user  = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();

        if (! $user) {
            return [
                'ok'    => false,
                'reply' => ['text' => 'Номер не найден. Обратитесь к руководству.'],
            ];
        }

        $session = TelegramPosSession::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'user_id' => $user->id,
                'state'   => 'main_menu',
                'data'    => null,
            ],
        );

        return [
            'ok'      => true,
            'reply'   => [
                'text' => "Добро пожаловать, {$user->name}!",
                // The phone reply-keyboard is removed so a user can't
                // accidentally re-submit their number.
                'kb'   => ['remove_keyboard' => true],
                'type' => 'reply',
            ],
            'session' => $session,
        ];
    }
}
