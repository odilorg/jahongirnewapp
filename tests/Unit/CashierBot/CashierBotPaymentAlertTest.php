<?php

namespace Tests\Unit\CashierBot;

use App\Jobs\SendTelegramNotificationJob;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\OwnerAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Cashier acts → System records → Owner knows."
 *
 * Asserts OwnerAlertService::alertCashierBotPayment dispatches a
 * SendTelegramNotificationJob with a clear human-readable payload
 * containing cashier, drawer, guest, booking, method, amount, time.
 */
class CashierBotPaymentAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Set a non-zero owner chat id so the service does not bail early.
        config(['services.owner_alert_bot.owner_chat_id' => 12345]);
    }

    private function makeTx(array $overrides = []): CashTransaction
    {
        // Drawer name must be unique per row (cash_drawers.name has a UNIQUE
        // index). The owner-alert message asserts on substring "Jahongir"
        // so keep that as a stable prefix.
        $drawer = CashDrawer::create(['name' => 'Jahongir-' . uniqid(), 'is_active' => true]);
        $user   = User::factory()->create(['name' => 'Aziz']);
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        return CashTransaction::create(array_merge([
            'cashier_shift_id'  => $shift->id,
            'type'              => 'in',
            'amount'            => 630_000,
            'currency'          => 'UZS',
            'category'          => 'sale',
            'beds24_booking_id' => 84213317,
            'guest_name'        => 'KEISUKE NOZAKI',
            'room_number'       => '203',
            'payment_method'    => 'card',
            'source_trigger'    => 'cashier_bot',
            'created_by'        => $user->id,
            'occurred_at'       => '2026-05-02 14:50:53',
        ], $overrides));
    }

    private function dispatchedPayload(): ?string
    {
        $captured = null;
        Queue::assertPushed(SendTelegramNotificationJob::class, function ($job) use (&$captured) {
            $reflect = new \ReflectionObject($job);
            $prop    = $reflect->getProperty('params');
            $prop->setAccessible(true);
            $captured = $prop->getValue($job);
            return true;
        });
        return $captured['text'] ?? null;
    }

    /** @test */
    public function alert_includes_cashier_drawer_guest_booking_method_amount_time(): void
    {
        Queue::fake();
        $tx = $this->makeTx();

        app(OwnerAlertService::class)->alertCashierBotPayment($tx);

        $text = $this->dispatchedPayload();
        $this->assertNotNull($text, 'Telegram notification job must be dispatched');
        $this->assertStringContainsString('Aziz',          $text);
        $this->assertStringContainsString('Jahongir',      $text);
        $this->assertStringContainsString('KEISUKE NOZAKI', $text);
        $this->assertStringContainsString('203',           $text);
        $this->assertStringContainsString('#84213317',     $text);
        $this->assertStringContainsString('Карта',         $text);
        $this->assertStringContainsString('630 000',       $text);
        $this->assertStringContainsString('UZS',           $text);
        $this->assertStringContainsString('02.05.2026 14:50', $text);
    }

    /** @test */
    public function method_label_handles_cash_transfer_and_legacy_null(): void
    {
        Queue::fake();

        app(OwnerAlertService::class)->alertCashierBotPayment($this->makeTx(['payment_method' => 'cash']));
        $this->assertStringContainsString('Наличные', $this->dispatchedPayload());

        Queue::fake(); // reset
        app(OwnerAlertService::class)->alertCashierBotPayment($this->makeTx(['payment_method' => 'transfer']));
        $this->assertStringContainsString('Перевод', $this->dispatchedPayload());

        Queue::fake();
        app(OwnerAlertService::class)->alertCashierBotPayment($this->makeTx(['payment_method' => null]));
        $this->assertStringContainsString('не указан', $this->dispatchedPayload());
    }

    /** @test */
    public function alert_skipped_when_owner_chat_id_unconfigured(): void
    {
        Queue::fake();
        config(['services.owner_alert_bot.owner_chat_id' => '']);

        // Re-resolve the singleton because constructor reads config.
        app()->forgetInstance(OwnerAlertService::class);
        app(OwnerAlertService::class)->alertCashierBotPayment($this->makeTx());

        Queue::assertNothingPushed();
    }

    /** @test */
    public function override_metadata_appended_when_present(): void
    {
        Queue::fake();
        $tx = $this->makeTx([
            'is_override'     => true,
            'override_tier'   => 'manager',
            'override_reason' => 'rate spike',
        ]);

        app(OwnerAlertService::class)->alertCashierBotPayment($tx);
        $text = $this->dispatchedPayload();
        $this->assertStringContainsString('Override', $text);
        $this->assertStringContainsString('manager',  $text);
        $this->assertStringContainsString('rate spike', $text);
    }

    /** @test */
    public function group_payment_metadata_appended_when_present(): void
    {
        Queue::fake();
        $tx = $this->makeTx([
            'is_group_payment'        => true,
            'group_master_booking_id' => 84213100,
        ]);

        app(OwnerAlertService::class)->alertCashierBotPayment($tx);
        $text = $this->dispatchedPayload();
        $this->assertStringContainsString('Групповая', $text);
        $this->assertStringContainsString('84213100',  $text);
    }
}
