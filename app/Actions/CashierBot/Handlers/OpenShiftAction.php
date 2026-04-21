<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Models\BeginningSaldo;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\ShiftHandover;
use App\Models\TelegramPosSession;
use App\Services\Cashier\BalanceCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles the "open_shift" callback from @j_cashier_bot.
 *
 * Pure extraction from CashierBotController::openShift. Preserves the
 * B2 fix (drawer-singleton race guard) verbatim — the OpenShiftSingleton
 * feature tests pin the atomic create + carry-forward + busy-detect
 * behaviour and must keep passing after this extraction.
 *
 * Five terminal states, each returned as a discriminated result:
 *   - user already has an open shift     → "Смена уже открыта" + main menu
 *   - no active CashDrawer configured    → "Нет активной кассы" (stop)
 *   - drawer busy (other cashier's shift)→ "Касса занята" (stop)
 *   - transaction failure                → logged + "Не удалось открыть" (stop)
 *   - shift opened                       → "Смена открыта" + balance + main menu
 *
 * Return shape lets the router decide whether to also render the main
 * menu after the reply. The session is passed through unchanged so the
 * router can hand it to the still-inline showMainMenu.
 *
 * @phpstan-type Reply array{text: string, kb?: array, type?: string}
 */
final class OpenShiftAction
{
    public function __construct(
        private readonly BalanceCalculator $balance,
    ) {}

    /**
     * @return array{replies: array<int, array>, show_main_menu: bool, session: TelegramPosSession}
     */
    public function execute(TelegramPosSession $session): array
    {
        if ($this->balance->getShift($session->user_id)) {
            return $this->result(['text' => 'Смена уже открыта.'], showMenu: true, session: $session);
        }

        $drawer = CashDrawer::where('is_active', true)->first();
        if (! $drawer) {
            return $this->result(['text' => 'Нет активной кассы.'], showMenu: false, session: $session);
        }

        try {
            [$shift, $busyWith] = $this->openAtomically($drawer, $session->user_id);
        } catch (\Throwable $e) {
            Log::error('CashierBot openShift failed', [
                'error'  => $e->getMessage(),
                'drawer' => $drawer->id,
                'user'   => $session->user_id,
            ]);

            return $this->result(['text' => 'Не удалось открыть смену. Попробуйте позже.'], showMenu: false, session: $session);
        }

        if ($busyWith) {
            $name = $busyWith->user->name ?? 'другой кассир';
            $text = "⚠️ Касса занята!\n\nСмена уже открыта: {$name}\nОткрыта: "
                . $busyWith->opened_at->timezone('Asia/Tashkent')->format('d.m H:i')
                . "\n\nДождитесь закрытия смены.";

            return $this->result(['text' => $text], showMenu: false, session: $session);
        }

        // Success path — compose the "shift opened" reply with the
        // carried-forward beginning balance if any.
        $balStr = $this->balance->fmtBal($this->balance->getBal($shift));
        $msg = "Смена открыта! Касса: {$drawer->name}";
        if ($balStr !== '0') {
            $msg .= "\nНачальный баланс: " . $balStr;
        }

        return $this->result(['text' => $msg], showMenu: true, session: $session);
    }

    /**
     * Atomic open with drawer lock (B2 guard). Two cashiers (or a Telegram
     * retry) hitting the "open shift" callback concurrently both used to
     * pass the "no open shift on this drawer" read and end up with two
     * open shifts on one drawer. Locking the drawer row and re-checking
     * inside the transaction removes the race.
     *
     * Carry-forward saldo rows are created inside the same transaction
     * so a partial open (shift without its BeginningSaldo) can't be
     * observed by a concurrent reader.
     *
     * @return array{0: CashierShift|null, 1: CashierShift|null} [opened, busyWith]
     */
    private function openAtomically(CashDrawer $drawer, ?int $userId): array
    {
        $shift    = null;
        $busyWith = null;

        DB::transaction(function () use ($drawer, $userId, &$shift, &$busyWith) {
            CashDrawer::where('id', $drawer->id)->lockForUpdate()->first();

            $existingShift = CashierShift::where('cash_drawer_id', $drawer->id)
                ->where('status', 'open')
                ->with('user')
                ->first();
            if ($existingShift) {
                $busyWith = $existingShift;

                return;
            }

            $shift = CashierShift::create([
                'cash_drawer_id' => $drawer->id,
                'user_id'        => $userId,
                'status'         => 'open',
                'opened_at'      => now(),
            ]);

            // Order by created_at with id as a stable tiebreaker for
            // same-second rows (B1).
            $prevHandover = ShiftHandover::whereHas(
                'outgoingShift',
                fn ($q) => $q->where('cash_drawer_id', $drawer->id),
            )
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($prevHandover) {
                foreach (['UZS' => $prevHandover->counted_uzs, 'USD' => $prevHandover->counted_usd, 'EUR' => $prevHandover->counted_eur] as $cur => $amt) {
                    if ($amt != 0) {
                        BeginningSaldo::create([
                            'cashier_shift_id' => $shift->id,
                            'currency'         => $cur,
                            'amount'           => $amt,
                        ]);
                    }
                }
            }
        });

        return [$shift, $busyWith];
    }

    /**
     * @return array{replies: array<int, array>, show_main_menu: bool, session: TelegramPosSession}
     */
    private function result(array $reply, bool $showMenu, TelegramPosSession $session): array
    {
        return [
            'replies'        => [$reply],
            'show_main_menu' => $showMenu,
            'session'        => $session,
        ];
    }
}
