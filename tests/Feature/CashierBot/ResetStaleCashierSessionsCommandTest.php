<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Multi-bot isolation contract for `cashier:reset-stale-sessions`.
 *
 * `telegram_pos_sessions` is shared across the cashier, housekeeping (`hk_*`),
 * and kitchen (`kitchen_*`) bots. The cleanup command MUST only touch cashier-
 * scoped states — never `hk_*` / `kitchen_*` / unrelated bots.
 *
 * If this test fails, the command's CASHIER_STATE_PREFIXES whitelist has drifted
 * or the SQL accidentally captures other bots.
 */
final class ResetStaleCashierSessionsCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_resets_stale_cashier_sessions_and_preserves_other_bots(): void
    {
        $user = User::factory()->create();

        // Stale (>7d) cashier sessions — should be reset
        $staleCashierShift = $this->seed('cashier-shift', 'shift_count_uzs', 999_001, $user->id, ['shift_id' => 1], days: 14);
        $staleCashierPay   = $this->seed('cashier-pay',   'payment_fx_amount', 999_002, $user->id, ['fx_presentation' => []], days: 10);

        // Stale housekeeping & kitchen sessions — must remain untouched
        $staleHk      = $this->seed('hk', 'hk_main',      999_010, $user->id, ['x' => 1], days: 30);
        $staleKitchen = $this->seed('kitchen', 'kitchen_main', 999_011, $user->id, ['y' => 2], days: 30);

        // Recent cashier session (within 7d) — must remain untouched
        $recentCashier = $this->seed('recent-cashier', 'expense_amount', 999_020, $user->id, ['recent' => true], days: 1);

        // Cashier session in main_menu / idle — must remain untouched (already clean)
        $cleanCashier = $this->seed('clean-cashier', 'main_menu', 999_021, $user->id, null, days: 30);
        $idleCashier  = $this->seed('idle-cashier',  'idle',      999_022, $user->id, null, days: 30);

        $this->artisan('cashier:reset-stale-sessions', ['--days' => 7])
            ->assertSuccessful();

        // Stale cashier sessions: state reset, data cleared
        $this->assertSame('main_menu', $staleCashierShift->fresh()->state);
        $this->assertNull($staleCashierShift->fresh()->data);
        $this->assertSame('main_menu', $staleCashierPay->fresh()->state);
        $this->assertNull($staleCashierPay->fresh()->data);

        // Other bots: untouched
        $this->assertSame('hk_main',      $staleHk->fresh()->state, 'Housekeeping bot session was wrongly touched');
        $this->assertSame(['x' => 1],     $staleHk->fresh()->data);
        $this->assertSame('kitchen_main', $staleKitchen->fresh()->state, 'Kitchen bot session was wrongly touched');
        $this->assertSame(['y' => 2],     $staleKitchen->fresh()->data);

        // Recent cashier: untouched
        $this->assertSame('expense_amount', $recentCashier->fresh()->state);
        $this->assertSame(['recent' => true], $recentCashier->fresh()->data);

        // Already-clean cashier: untouched
        $this->assertSame('main_menu', $cleanCashier->fresh()->state);
        $this->assertSame('idle',      $idleCashier->fresh()->state);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $stale = $this->seed('dry-run', 'shift_count_uzs', 999_100, $user->id, ['shift_id' => 1], days: 30);

        $this->artisan('cashier:reset-stale-sessions', ['--days' => 7, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('shift_count_uzs',  $stale->fresh()->state);
        $this->assertSame(['shift_id' => 1],  $stale->fresh()->data);
    }

    private function seed(string $tag, string $state, int $chatId, int $userId, ?array $data, int $days): TelegramPosSession
    {
        $session = TelegramPosSession::create([
            'chat_id' => $chatId,
            'user_id' => $userId,
            'state'   => $state,
            'data'    => $data,
        ]);
        // Force updated_at older than the cutoff. Eloquent::update touches timestamps,
        // so use a raw query to bypass.
        TelegramPosSession::where('id', $session->id)
            ->update(['updated_at' => Carbon::now()->subDays($days)]);

        return $session->fresh();
    }
}
