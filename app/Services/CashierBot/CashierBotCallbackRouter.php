<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

use App\Http\Controllers\CashierBotController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes Telegram callback_query updates from the cashier bot to the right
 * controller handler, and owns the financial-callback idempotency lifecycle
 * claim step.
 *
 * Phase A2 (this class) is a behavior-preserving extraction. Subsequent
 * phases will move handler bodies + the succeed/fail idempotency steps in
 * here as their callers (the confirm_* handlers) get extracted to Actions.
 *
 * @internal Currently a pass-through stub that delegates to
 *           CashierBotController::handleCallback. Commit A2/3 will move the
 *           dispatch table + claimCallback into this class.
 */
final class CashierBotCallbackRouter
{
    /**
     * Dispatch a Telegram callback_query to the right cashier-bot handler.
     *
     * The controller calls this from `handleCallback`. The router must answer
     * the callback (aCb), perform owner-bot regex delegation, run idempotency
     * claim for financial confirm_* actions, look up the session, and route
     * to the matching handler — all on the controller via its now-public
     * @internal handler methods.
     *
     * Pass-through stub for commit A2/2; real dispatch lands in A2/3.
     */
    public function dispatch(array $callbackQuery, CashierBotController $controller): Response
    {
        return $controller->handleCallbackInternal($callbackQuery);
    }
}
