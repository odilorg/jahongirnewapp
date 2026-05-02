<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Http\Controllers\OwnerBotController;
use App\Models\TelegramPosSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes Telegram callback_query updates from the cashier bot to the right
 * controller handler. Owns the financial-callback idempotency claim step.
 *
 * Phase A2 extraction: behavior-preserving, no logic changes vs the
 * original `CashierBotController::handleCallback` body. Subsequent phases
 * will move handler bodies + the succeed/fail idempotency steps in here as
 * their callers (the confirm_* handlers) get extracted to Actions.
 */
final class CashierBotCallbackRouter
{
    /**
     * Callback actions that create financial side effects — must go through
     * the claim/succeed/fail idempotency lifecycle.
     */
    private const IDEMPOTENT_ACTIONS = [
        'confirm_payment',
        'confirm_expense',
        'confirm_exchange',
        'confirm_close',
        'confirm_cash_in',
    ];

    /**
     * Dispatch a Telegram callback_query to the right cashier-bot handler.
     *
     * Order of operations (must match the pre-extraction handleCallback):
     *   1. Pull chat_id / data / callback_id from payload
     *   2. Answer the callback (aCb) so Telegram stops the spinner
     *   3. Owner-bot expense approve/reject regex — delegates to OwnerBotController
     *   4. Idempotency claim for the 5 financial confirm_* actions
     *   5. Session lookup
     *   6. Match dispatch table → handler method on $controller
     */
    public function dispatch(array $cb, CashierBotController $controller): Response
    {
        $chatId     = $cb['message']['chat']['id'] ?? null;
        $data       = $cb['data'] ?? '';
        $callbackId = $cb['id'] ?? '';

        if (! $chatId) {
            return response('OK');
        }

        $controller->aCb($callbackId);

        // Owner approval callbacks (owner may not have a session)
        if (preg_match('/^(approve|reject)_expense_(\d+)$/', $data, $matches)) {
            return app(OwnerBotController::class)
                ->handleExpenseAction(
                    $chatId,
                    $cb['message']['message_id'] ?? null,
                    $callbackId,
                    $matches[1],
                    (int) $matches[2]
                );
        }

        // Idempotency guard for financial confirm actions
        if (in_array($data, self::IDEMPOTENT_ACTIONS, true) && $callbackId) {
            $claimResult = $this->claimCallback($callbackId, $chatId, $data);
            if ($claimResult !== 'claimed') {
                Log::info('CashierBot: callback not claimable', [
                    'callback_id' => $callbackId,
                    'action'      => $data,
                    'result'      => $claimResult,
                ]);
                $msg = $claimResult === 'succeeded'
                    ? "⚠️ Эта операция уже обработана."
                    : "⏳ Операция в процессе, подождите.";
                $controller->send($chatId, $msg);
                return response('OK');
            }
        }

        $s = TelegramPosSession::where('chat_id', $chatId)->first();
        if (! $s) {
            return response('OK');
        }

        return match (true) {
            $data === 'open_shift'         => $controller->openShift($s, $chatId),
            $data === 'payment'            => $controller->startPayment($s, $chatId),
            $data === 'expense'            => $controller->startExpense($s, $chatId),
            $data === 'exchange'           => $controller->startExchange($s, $chatId),
            $data === 'balance'            => $controller->dispatchReply($chatId, app(\App\Actions\CashierBot\Handlers\ShowBalanceAction::class)->execute($s->user_id)),
            $data === 'cash_in'            => $controller->startCashIn($s, $chatId),
            $data === 'confirm_cash_in'    => $controller->confirmCashIn($s, $chatId, $callbackId),
            $data === 'close_shift'        => $controller->startClose($s, $chatId),
            $data === 'menu'               => $controller->showMainMenu($chatId, $s),
            str_starts_with($data, 'guest_')   => $controller->selectGuest($s, $chatId, $data),
            str_starts_with($data, 'pick_date_') => $controller->pickArrivalDate($s, $chatId, $data),
            str_starts_with($data, 'cur_')     => $controller->selectCur($s, $chatId, $data),
            $data === 'fx_confirm_amount'  => $controller->fxConfirmAmount($s, $chatId),
            str_starts_with($data, 'excur_')   => $controller->selectExCur($s, $chatId, $data),
            str_starts_with($data, 'exout_')   => $controller->selectExOutCur($s, $chatId, $data),
            str_starts_with($data, 'method_')  => $controller->selectMethod($s, $chatId, $data),
            str_starts_with($data, 'expcat_')  => $controller->selectExpCat($s, $chatId, $data),
            $data === 'confirm_payment'    => $controller->confirmPayment($s, $chatId, $callbackId),
            $data === 'confirm_expense'    => $controller->confirmExpense($s, $chatId, $callbackId),
            $data === 'confirm_exchange'   => $controller->confirmExchange($s, $chatId, $callbackId),
            $data === 'confirm_close'      => $controller->confirmClose($s, $chatId, $callbackId),
            $data === 'cancel'             => $controller->showMainMenu($chatId, $s),
            $data === 'my_txns'            => $controller->dispatchReply($chatId, app(\App\Actions\CashierBot\Handlers\ShowMyTransactionsAction::class)->execute($s->user_id)),
            $data === 'guide'              => $controller->dispatchGuide($chatId, null),
            str_starts_with($data, 'guide_')   => $controller->dispatchGuide($chatId, substr($data, 6)),
            default                            => response('OK'),
        };
    }

    /**
     * Attempt to claim a callback for processing.
     * Race-safe via the telegram_processed_callbacks UNIQUE constraint.
     *
     * @return 'claimed'|'succeeded'|'processing' — what happened
     */
    private function claimCallback(string $callbackId, int $chatId, string $action): string
    {
        $existing = DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->first();

        if ($existing) {
            if ($existing->status === 'succeeded')  return 'succeeded';
            if ($existing->status === 'processing') return 'processing';

            // status === 'failed' → allow retry by deleting the failed row
            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', $callbackId)
                ->where('status', 'failed')
                ->delete();
        }

        try {
            DB::table('telegram_processed_callbacks')->insert([
                'callback_query_id' => $callbackId,
                'chat_id'           => $chatId,
                'action'            => $action,
                'status'            => 'processing',
                'claimed_at'        => now(),
            ]);
            return 'claimed';
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost the race — another request claimed it between check + insert
            $row = DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', $callbackId)
                ->first();
            return $row?->status === 'succeeded' ? 'succeeded' : 'processing';
        }
    }
}
