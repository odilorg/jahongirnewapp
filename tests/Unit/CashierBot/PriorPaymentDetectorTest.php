<?php

declare(strict_types=1);

namespace Tests\Unit\CashierBot;

use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\CashierBot\PriorPaymentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the prior-payment detector that powers the
 * confirmation-screen warning. Locks severity-tier classification:
 *
 *   - tier='duplicate' → same currency + amount within ±1 unit
 *   - tier='split'     → prior exists but amount differs (legit top-up)
 *   - null             → no prior, no warning
 *
 * Real-world incident 2026-05-03: source-mismatch blind spot let an
 * operator double-record a Beds24-already-paid booking via the bot.
 * The guard only checked cashier_bot rows; beds24_external mirrors
 * were ignored. This detector covers BOTH sources.
 */
class PriorPaymentDetectorTest extends TestCase
{
    use RefreshDatabase;

    private CashierShift $shift;
    private PriorPaymentDetector $detector;

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
        $this->detector = new PriorPaymentDetector();
    }

    private function createTx(string $source, int $bookingId, float $amount, string $currency = 'UZS', string $method = 'cash'): CashTransaction
    {
        return CashTransaction::create([
            'cashier_shift_id'  => $this->shift->id,
            'type'              => 'in',
            'amount'            => $amount,
            'currency'          => $currency,
            'category'          => 'sale',
            'beds24_booking_id' => $bookingId,
            'payment_method'    => $method,
            'source_trigger'    => $source,
            'occurred_at'       => now(),
        ]);
    }

    public function test_no_prior_returns_null(): void
    {
        $this->assertNull($this->detector->detect(99999, 100_000, 'UZS'));
    }

    public function test_prior_cashier_bot_with_matching_amount_is_duplicate(): void
    {
        $this->createTx('cashier_bot', 12345, 630_000, 'UZS', 'card');
        $result = $this->detector->detect(12345, 630_000, 'UZS');

        $this->assertNotNull($result);
        $this->assertSame('duplicate', $result['tier']);
    }

    public function test_prior_beds24_external_with_matching_amount_is_duplicate(): void
    {
        // The real-world incident shape: Beds24-mirrored payment exists,
        // operator now records same amount via cashier bot.
        $this->createTx('beds24_external', 12345, 630_000, 'UZS', 'card');
        $result = $this->detector->detect(12345, 630_000, 'UZS');

        $this->assertNotNull($result);
        $this->assertSame('duplicate', $result['tier']);
    }

    public function test_prior_with_different_amount_is_split(): void
    {
        // Beds24 collected 30% deposit; operator now records 70% balance.
        $this->createTx('beds24_external', 12345, 200_000, 'UZS');
        $result = $this->detector->detect(12345, 500_000, 'UZS');

        $this->assertNotNull($result);
        $this->assertSame('split', $result['tier']);
    }

    public function test_prior_in_different_currency_is_split(): void
    {
        $this->createTx('beds24_external', 12345, 50, 'USD');
        $result = $this->detector->detect(12345, 630_000, 'UZS');

        $this->assertNotNull($result);
        $this->assertSame('split', $result['tier']);
    }

    public function test_prior_within_tolerance_is_duplicate(): void
    {
        // Float drift between Beds24's 65.90 USD record and operator's 65.90 entry.
        $this->createTx('beds24_external', 12345, 65.90, 'USD');
        $result = $this->detector->detect(12345, 65.90, 'USD');

        $this->assertNotNull($result);
        $this->assertSame('duplicate', $result['tier']);
    }

    public function test_soft_deleted_prior_is_ignored(): void
    {
        // Admin already deleted the duplicate via Filament — fresh attempt
        // should NOT re-trigger the warning.
        $tx = $this->createTx('cashier_bot', 12345, 630_000, 'UZS');
        $tx->delete();

        $this->assertNull($this->detector->detect(12345, 630_000, 'UZS'));
    }

    public function test_warning_text_renders_for_duplicate(): void
    {
        $this->createTx('beds24_external', 12345, 630_000, 'UZS', 'card');
        $detection = $this->detector->detect(12345, 630_000, 'UZS');
        $text      = $this->detector->formatWarning($detection, 12345);

        $this->assertStringContainsString('🚨', $text);
        $this->assertStringContainsString('ВНИМАНИЕ', $text);
        $this->assertStringContainsString('12345', $text);
        $this->assertStringContainsString('Beds24', $text);
        $this->assertStringContainsString('630 000', $text);
    }

    public function test_warning_text_renders_for_split(): void
    {
        $this->createTx('beds24_external', 12345, 200_000, 'UZS', 'card');
        $detection = $this->detector->detect(12345, 500_000, 'UZS');
        $text      = $this->detector->formatWarning($detection, 12345);

        $this->assertStringContainsString('⚠', $text);
        $this->assertStringContainsString('отдельную транзакцию', $text);
        $this->assertStringContainsString('12345', $text);
    }

    public function test_warning_text_empty_when_no_detection(): void
    {
        $this->assertSame('', $this->detector->formatWarning(null, 12345));
    }
}
