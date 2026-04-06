<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotOperator;
use Illuminate\Support\Facades\Log;

/**
 * Resolves and authenticates a BotOperator from a raw Telegram update payload.
 *
 * Identity is based on from.id (telegram_user_id), NOT chat_id.
 * from.id is stable across DMs, groups, and inline usage.
 */
class BotOperatorAuth
{
    /**
     * Resolve the authorized operator from a Telegram update.
     *
     * Returns null if:
     *  - No from.id is present in the update
     *  - The user is not in bot_operators
     *  - The operator's is_active = false
     *
     * @param  array  $update  Raw Telegram update array
     * @return BotOperator|null
     */
    public function fromUpdate(array $update): ?BotOperator
    {
        $userId = $this->extractUserId($update);

        if (! $userId) {
            return null;
        }

        $operator = BotOperator::where('telegram_user_id', $userId)->first();

        if (! $operator) {
            Log::warning('BotOperatorAuth: unregistered user', ['telegram_user_id' => $userId]);
            return null;
        }

        if (! $operator->is_active) {
            Log::warning('BotOperatorAuth: inactive operator', [
                'telegram_user_id' => $userId,
                'name'             => $operator->name,
                'role'             => $operator->role,
            ]);
            return null;
        }

        return $operator;
    }

    /**
     * Extract the Telegram user ID (from.id) from an update without DB lookup.
     * Used for logging denied attempts.
     */
    public function extractUserId(array $update): ?string
    {
        $id = data_get($update, 'message.from.id')
           ?? data_get($update, 'callback_query.from.id');

        return $id !== null ? (string) $id : null;
    }
}
