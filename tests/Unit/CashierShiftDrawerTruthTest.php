<?php

namespace Tests\Unit;

use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for BUG-06:
 * CashierShift balance methods must exclude beds24_external rows
 * and only count drawer-truth sources (cashier_bot, manual_admin).
 */
class CashierShiftDrawerTruthTest extends TestCase
{
    use RefreshDatabase;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $drawer = CashDrawer::create(['name' => 'Test Drawer', 'is_active' => true]);
        $user   = User::factory()->create();

        $this->shift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function createTx(string $source, string $type, float $amount, string $currency = 'UZS', ?string $paymentMethod = null): void
    {
        CashTransaction::create([
            'cashier_shift_id' => $this->shift->id,
            'type'             => $type,
            'amount'           => $amount,
            'currency'         => $currency,
            'category'         => 'sale',
            'source_trigger'   => $source,
            'payment_method'   => $paymentMethod,
            'occurred_at'      => now(),
        ]);
    }

    // ── getTotalCashInForCurrency ──────────────────────────────────

    /** @test */
    public function cash_in_for_currency_excludes_beds24_external(): void
    {
        $this->createTx('cashier_bot',     'in', 100_000);
        $this->createTx('manual_admin',    'in',  50_000);
        $this->createTx('beds24_external', 'in', 200_000); // mirror row — must be excluded

        $total = $this->shift->getTotalCashInForCurrency(Currency::UZS);

        $this->assertEquals(150_000, $total,
            'beds24_external row must not be counted in cash-in balance');
    }

    /** @test */
    public function cash_in_for_currency_includes_cashier_bot_and_manual_admin(): void
    {
        $this->createTx('cashier_bot',  'in', 300_000);
        $this->createTx('manual_admin', 'in', 100_000);

        $total = $this->shift->getTotalCashInForCurrency(Currency::UZS);

        $this->assertEquals(400_000, $total);
    }

    /** @test */
    public function cash_in_for_currency_with_only_beds24_external_returns_zero(): void
    {
        $this->createTx('beds24_external', 'in', 500_000);

        $total = $this->shift->getTotalCashInForCurrency(Currency::UZS);

        $this->assertEquals(0, $total,
            'Drawer balance must be 0 when all rows are beds24_external');
    }

    // ── getTotalCashOutForCurrency ─────────────────────────────────

    /** @test */
    public function cash_out_for_currency_excludes_beds24_external(): void
    {
        $this->createTx('cashier_bot',     'out', 30_000);
        $this->createTx('beds24_external', 'out', 70_000); // must be excluded

        $total = $this->shift->getTotalCashOutForCurrency(Currency::UZS);

        $this->assertEquals(30_000, $total);
    }

    // ── getNetBalanceForCurrency / getRunningBalanceForCurrency ────

    /** @test */
    public function net_balance_excludes_beds24_external(): void
    {
        // Beginning saldo: 0 (no BeginningSaldo row)
        $this->createTx('cashier_bot',     'in',  200_000);
        $this->createTx('beds24_external', 'in',  999_999); // must not inflate balance
        $this->createTx('cashier_bot',     'out',  50_000);

        // Expected: 0 + 200_000 - 50_000 = 150_000
        $net = $this->shift->getNetBalanceForCurrency(Currency::UZS);

        $this->assertEquals(150_000, $net,
            'Net balance must ignore beds24_external rows');
    }

    /** @test */
    public function running_balance_matches_net_balance(): void
    {
        $this->createTx('cashier_bot',     'in', 400_000);
        $this->createTx('beds24_external', 'in', 100_000);
        $this->createTx('manual_admin',    'out', 80_000);

        $this->assertEquals(
            $this->shift->getNetBalanceForCurrency(Currency::UZS),
            $this->shift->getRunningBalanceForCurrency(Currency::UZS),
            'getRunningBalanceForCurrency and getNetBalanceForCurrency must agree'
        );
    }

    // ── multi-currency isolation ───────────────────────────────────

    /** @test */
    public function balance_is_isolated_per_currency(): void
    {
        $this->createTx('cashier_bot', 'in', 500_000, 'UZS');
        $this->createTx('cashier_bot', 'in', 200,     'USD');
        $this->createTx('cashier_bot', 'in', 150,     'EUR');

        $this->assertEquals(500_000, $this->shift->getTotalCashInForCurrency(Currency::UZS));
        $this->assertEquals(200,     $this->shift->getTotalCashInForCurrency(Currency::USD));
        $this->assertEquals(150,     $this->shift->getTotalCashInForCurrency(Currency::EUR));
    }

    // ── getTotalCashInAttribute / getTotalCashOutAttribute ─────────

    /** @test */
    public function total_cash_in_attribute_excludes_beds24_external(): void
    {
        $this->createTx('cashier_bot',     'in', 80_000);
        $this->createTx('beds24_external', 'in', 20_000);

        $this->assertEquals(80_000, $this->shift->total_cash_in);
    }

    /** @test */
    public function total_cash_out_attribute_excludes_beds24_external(): void
    {
        $this->createTx('cashier_bot',     'out', 10_000);
        $this->createTx('beds24_external', 'out',  5_000);

        $this->assertEquals(10_000, $this->shift->total_cash_out);
    }

    // ── payment_method drawer-impact rules ─────────────────────────
    //
    // BUG: card / transfer payments were inflating the cashier's drawer
    // balance because drawerTruth() only filtered by source_trigger.
    // Card and transfer collections never enter the physical drawer and
    // must not affect cashier balance. NULL is treated as cash for
    // backward compatibility with legacy rows + expenses written before
    // payment_method existed on the bot path.

    /** @test */
    public function card_payments_do_not_affect_drawer_balance(): void
    {
        $this->createTx('cashier_bot', 'in', 100_000, 'UZS', 'cash');
        $this->createTx('cashier_bot', 'in', 630_000, 'UZS', 'card');

        $this->assertEquals(
            100_000,
            $this->shift->getTotalCashInForCurrency(Currency::UZS),
            'Card payments must not be counted in the cashier drawer balance'
        );
    }

    /** @test */
    public function transfer_payments_do_not_affect_drawer_balance(): void
    {
        $this->createTx('cashier_bot', 'in',  50_000, 'UZS', 'cash');
        $this->createTx('cashier_bot', 'in', 200_000, 'UZS', 'transfer');

        $this->assertEquals(
            50_000,
            $this->shift->getTotalCashInForCurrency(Currency::UZS),
            'Transfer payments must not be counted in the cashier drawer balance'
        );
    }

    /** @test */
    public function cash_payment_method_is_counted(): void
    {
        $this->createTx('cashier_bot', 'in', 75_000, 'UZS', 'cash');

        $this->assertEquals(75_000, $this->shift->getTotalCashInForCurrency(Currency::UZS));
    }

    /** @test */
    public function null_payment_method_is_counted_as_legacy_cash(): void
    {
        // Legacy rows + expense rows pre-date the payment_method field
        // on the bot path. Treat NULL as cash to preserve those balances.
        $this->createTx('cashier_bot',  'in',  300_000, 'UZS', null);
        $this->createTx('manual_admin', 'out',  50_000, 'UZS', null);

        $this->assertEquals(300_000, $this->shift->getTotalCashInForCurrency(Currency::UZS));
        $this->assertEquals( 50_000, $this->shift->getTotalCashOutForCurrency(Currency::UZS));
    }

    /** @test */
    public function empty_string_payment_method_is_counted_as_legacy_cash(): void
    {
        // Defense in depth: some legacy writers may have used '' instead
        // of NULL. Treat '' identically.
        $this->createTx('cashier_bot', 'in', 120_000, 'UZS', '');

        $this->assertEquals(120_000, $this->shift->getTotalCashInForCurrency(Currency::UZS));
    }

    /** @test */
    public function net_balance_excludes_card_and_transfer(): void
    {
        $this->createTx('cashier_bot', 'in', 200_000, 'UZS', 'cash');
        $this->createTx('cashier_bot', 'in', 630_000, 'UZS', 'card');     // must not inflate
        $this->createTx('cashier_bot', 'in', 100_000, 'UZS', 'transfer'); // must not inflate
        $this->createTx('cashier_bot', 'out', 50_000, 'UZS', null);       // legacy expense

        $this->assertEquals(
            150_000,
            $this->shift->getNetBalanceForCurrency(Currency::UZS),
            'Net balance must ignore card and transfer payments'
        );
    }

    /** @test */
    public function unknown_payment_method_is_excluded_by_default(): void
    {
        // Defense in depth: any new method we don't explicitly recognize
        // as cash should be excluded from the drawer rather than included.
        $this->createTx('cashier_bot', 'in', 80_000, 'UZS', 'cash');
        $this->createTx('cashier_bot', 'in', 90_000, 'UZS', 'crypto');

        $this->assertEquals(
            80_000,
            $this->shift->getTotalCashInForCurrency(Currency::UZS),
            'Unknown payment methods must default to excluded from drawer'
        );
    }
}
