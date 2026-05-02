<?php

namespace Tests\Unit\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: when an operator tries to record a payment for a booking
 * that already has a cashier_bot CashTransaction row, the bot must show
 * a clear message — including method, amount, currency and date — so
 * the operator does not blindly retry. Replaces the previous misleading
 * "❌ Ошибка при записи оплаты. Попробуйте снова." message that came
 * from the generic Exception handler.
 */
class DuplicatePaymentMessageTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(): CashierShift
    {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user   = User::factory()->create();
        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
    }

    private function callFormat(string $method, int $bookingId): string
    {
        $controller = app(CashierBotController::class);
        $reflect    = new \ReflectionMethod($controller, $method);
        $reflect->setAccessible(true);
        return $reflect->invoke($controller, $bookingId);
    }

    /** @test */
    public function standalone_duplicate_message_includes_booking_method_amount_and_date(): void
    {
        $shift = $this->makeShift();
        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'amount'           => 630_000,
            'currency'         => 'UZS',
            'category'         => 'sale',
            'source_trigger'   => 'cashier_bot',
            'payment_method'   => 'card',
            'beds24_booking_id'=> 84213317,
            'occurred_at'      => '2026-05-02 14:50:53',
        ]);

        $msg = $this->callFormat("formatDuplicatePaymentMessage", 84213317);

        $this->assertStringContainsString('#84213317', $msg);
        $this->assertStringContainsString('карта',     $msg);
        $this->assertStringContainsString('630 000',   $msg);
        $this->assertStringContainsString('UZS',       $msg);
        $this->assertStringContainsString('02.05.2026', $msg);
        $this->assertStringContainsString('Повторное внесение невозможно', $msg);
        // Crucially, must NOT tell operator to retry.
        $this->assertStringNotContainsString('Попробуйте снова', $msg);
        $this->assertStringNotContainsString('отменено', $msg);
    }

    /** @test */
    public function standalone_duplicate_handles_cash_method_label(): void
    {
        $shift = $this->makeShift();
        CashTransaction::create([
            'cashier_shift_id' => $shift->id, 'type' => 'in', 'amount' => 200_000,
            'currency' => 'UZS', 'category' => 'sale', 'source_trigger' => 'cashier_bot',
            'payment_method' => 'cash', 'beds24_booking_id' => 99001, 'occurred_at' => now(),
        ]);
        $this->assertStringContainsString('наличные', $this->callFormat("formatDuplicatePaymentMessage", 99001));
    }

    /** @test */
    public function standalone_duplicate_handles_transfer_method_label(): void
    {
        $shift = $this->makeShift();
        CashTransaction::create([
            'cashier_shift_id' => $shift->id, 'type' => 'in', 'amount' => 50_000,
            'currency' => 'UZS', 'category' => 'sale', 'source_trigger' => 'cashier_bot',
            'payment_method' => 'transfer', 'beds24_booking_id' => 99002, 'occurred_at' => now(),
        ]);
        $this->assertStringContainsString('перевод', $this->callFormat("formatDuplicatePaymentMessage", 99002));
    }

    /** @test */
    public function standalone_duplicate_handles_legacy_null_method(): void
    {
        $shift = $this->makeShift();
        CashTransaction::create([
            'cashier_shift_id' => $shift->id, 'type' => 'in', 'amount' => 100_000,
            'currency' => 'UZS', 'category' => 'sale', 'source_trigger' => 'cashier_bot',
            'payment_method' => null, 'beds24_booking_id' => 99003, 'occurred_at' => now(),
        ]);
        $msg = $this->callFormat("formatDuplicatePaymentMessage", 99003);
        $this->assertStringContainsString('не указан', $msg);
        // Legacy rows must still produce a useful, non-confusing message.
        $this->assertStringContainsString('100 000', $msg);
    }

    /** @test */
    public function falls_back_to_generic_when_booking_id_missing(): void
    {
        $msg = $this->callFormat("formatDuplicatePaymentMessage", 0);
        $this->assertStringContainsString('уже зарегистрирована', $msg);
        $this->assertStringContainsString('Повторное внесение невозможно', $msg);
    }

    /** @test */
    public function falls_back_to_generic_when_no_existing_tx_found(): void
    {
        // Booking with no recorded payment — defensive path; should not crash.
        $msg = $this->callFormat("formatDuplicatePaymentMessage", 424242);
        $this->assertStringContainsString('уже зарегистрирована', $msg);
    }

    /** @test */
    public function group_duplicate_message_falls_back_when_no_history(): void
    {
        $msg = $this->callFormat("formatDuplicateGroupPaymentMessage", 0);
        $this->assertStringContainsString('групповой', $msg);
        $this->assertStringContainsString('обратитесь к менеджеру', $msg);
    }
}
