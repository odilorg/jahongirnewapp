<?php

namespace Tests\Feature;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Http\Controllers\CashierBotController;
use App\Models\Beds24Booking;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\BotPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Microphases 7 & 8: legacy payment path blocked then wiring removed.
 *
 * Microphase 7 replaced the CashierPaymentService fallback with hard blocks.
 * Microphase 8 deleted CashierPaymentService and removed it from the controller.
 *
 * Blocked entry points (verified to send block message and not advance session):
 *   (A) selectGuest() with bid='manual'
 *   (B) selectGuest() when FX preparePayment() throws
 *   (C) confirmPayment() when fx_presentation is absent from session data
 *   (D) selectCur() when FX presentation is absent or corrupted
 *
 * The FX/canonical path (fx_presentation is present) is preserved.
 */
class LegacyPaymentFallbackBlockTest extends TestCase
{
    use DatabaseTransactions;

    private TestableLegacyBlockController $controller;
    private BotPaymentService $botPaymentServiceMock;
    private int $shiftId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botPaymentServiceMock = $this->createMock(BotPaymentService::class);

        // Bind mock into the container so make() resolves it
        $this->app->bind(BotPaymentService::class, fn () => $this->botPaymentServiceMock);

        $this->controller = $this->app->make(TestableLegacyBlockController::class);

        // Minimal DB setup — shift required by confirmPayment()
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user   = User::factory()->create();
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
        $this->shiftId = $shift->id;
        $this->userId  = $user->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Minimal fake session object used by selectGuest() and confirmPayment(). */
    private function makeSession(array $data = []): object
    {
        return new class($this->userId, $data) {
            public array $data;
            public int $user_id;
            public ?string $capturedState = null;

            public function __construct(int $userId, array $data) {
                $this->user_id = $userId;
                $this->data    = $data;
            }

            public function update(array $attrs): void {
                if (isset($attrs['data']))  $this->data  = $attrs['data'];
                if (isset($attrs['state'])) $this->capturedState = $attrs['state'];
            }
        };
    }

    /** A minimal fx_presentation array that PaymentPresentation::fromArray() can parse. */
    private function makeFxPresentation(): array
    {
        return PaymentPresentation::fromArray([
            'beds24_booking_id' => 'B_TEST_001',
            'sync_id'           => 1,
            'daily_rate_id'     => 1,
            'guest_name'        => 'Test Guest',
            'arrival_date'      => now()->addDay()->toDateString(),
            'uzs_presented'     => 1_280_000,
            'eur_presented'     => 92,
            'rub_presented'     => 9200,
            'fx_rate_date'      => now()->format('d.m.Y'),
            'bot_session_id'    => 'sess-test',
            'presented_at'      => now()->toIso8601String(),
        ])->toArray();
    }

    // ── (A) selectGuest: bid = 'manual' ──────────────────────────────────────

    /** @test */
    public function manual_guest_entry_is_blocked_with_fx_unavailable_message(): void
    {
        $s = $this->makeSession(['shift_id' => $this->shiftId]);

        $this->controller->callSelectGuest($s, 12345, 'guest_manual');

        $this->assertStringContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages),
            'Block message must be shown for manual entry attempt'
        );
    }

    /** @test */
    public function manual_entry_does_not_advance_session_to_payment_amount_state(): void
    {
        $s = $this->makeSession(['shift_id' => $this->shiftId]);

        $this->controller->callSelectGuest($s, 12345, 'guest_manual');

        $this->assertNotEquals(
            'payment_amount',
            $s->capturedState,
            'Session must not be set to payment_amount when manual entry is blocked'
        );
    }

    /** @test */
    public function manual_entry_does_not_invoke_bot_payment_service(): void
    {
        // CashierPaymentService is deleted (MP8). Verify BotPaymentService is also not called.
        $this->botPaymentServiceMock->expects($this->never())->method('recordPayment');

        $s = $this->makeSession(['shift_id' => $this->shiftId]);
        $this->controller->callSelectGuest($s, 12345, 'guest_manual');
    }

    // ── (B) selectGuest: FX preparePayment() fails ────────────────────────────

    /** @test */
    public function fx_unavailable_booking_is_blocked_with_operator_message(): void
    {
        // Booking exists in local DB but preparePayment() throws (FX service down)
        Beds24Booking::factory()->create([
            'beds24_booking_id' => 'B_FX_DOWN',
            'booking_status'    => 'confirmed',
            'property_id'       => '41097',
            'total_amount'      => 100.0,
        ]);

        $this->botPaymentServiceMock
            ->method('preparePayment')
            ->willThrowException(new \RuntimeException('FX sync unavailable'));

        $s = $this->makeSession(['shift_id' => $this->shiftId, '_live_guests' => []]);

        $this->controller->callSelectGuest($s, 12345, 'guest_B_FX_DOWN');

        $this->assertStringContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages),
            'FX failure must show block message, not fall through to manual amount entry'
        );
    }

    /** @test */
    public function fx_failure_does_not_advance_session_to_payment_amount_state(): void
    {
        Beds24Booking::factory()->create([
            'beds24_booking_id' => 'B_FX_FAIL',
            'booking_status'    => 'confirmed',
            'property_id'       => '41097',
            'total_amount'      => 100.0,
        ]);

        $this->botPaymentServiceMock
            ->method('preparePayment')
            ->willThrowException(new \RuntimeException('FX service error'));

        $s = $this->makeSession(['shift_id' => $this->shiftId, '_live_guests' => []]);

        $this->controller->callSelectGuest($s, 12345, 'guest_B_FX_FAIL');

        $this->assertNotEquals(
            'payment_amount',
            $s->capturedState,
            'Session must not advance to payment_amount when FX is unavailable'
        );
    }

    /** @test */
    public function fx_failure_does_not_invoke_legacy_payment_service(): void
    {
        Beds24Booking::factory()->create([
            'beds24_booking_id' => 'B_FX_ERR',
            'booking_status'    => 'confirmed',
            'property_id'       => '41097',
            'total_amount'      => 100.0,
        ]);

        $this->botPaymentServiceMock
            ->method('preparePayment')
            ->willThrowException(new \RuntimeException('FX error'));

        // CashierPaymentService is deleted (MP8). Verify recordPayment is also not called.
        $this->botPaymentServiceMock->expects($this->never())->method('recordPayment');

        $s = $this->makeSession(['shift_id' => $this->shiftId, '_live_guests' => []]);
        $this->controller->callSelectGuest($s, 12345, 'guest_B_FX_ERR');
    }

    // ── (C) confirmPayment: fx_presentation absent ────────────────────────────

    /** @test */
    public function confirm_payment_without_fx_presentation_is_blocked(): void
    {
        $s = $this->makeSession([
            'shift_id'       => $this->shiftId,
            'fx_presentation' => null,
            'currency'       => 'UZS',
            'amount'         => 1_280_000,
            'method'         => 'cash',
        ]);

        $this->controller->callConfirmPayment($s, 12345);

        $this->assertStringContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages),
            'confirmPayment() must show block message when fx_presentation is absent'
        );
    }

    /** @test */
    public function confirm_payment_without_fx_presentation_does_not_call_bot_payment_service(): void
    {
        // CashierPaymentService is deleted (MP8). Verify BotPaymentService is also not called.
        $this->botPaymentServiceMock->expects($this->never())->method('recordPayment');

        $s = $this->makeSession([
            'shift_id'       => $this->shiftId,
            'fx_presentation' => null,
            'currency'       => 'UZS',
            'amount'         => 1_280_000,
            'method'         => 'cash',
        ]);

        $this->controller->callConfirmPayment($s, 12345);
    }

    /** @test */
    public function confirm_payment_without_fx_presentation_writes_no_cash_transaction(): void
    {
        // Capture pre-existing row count (production DB rows are visible in the test transaction)
        $before = CashTransaction::count();

        $s = $this->makeSession([
            'shift_id'       => $this->shiftId,
            'fx_presentation' => null,
            'currency'       => 'UZS',
            'amount'         => 1_280_000,
            'method'         => 'cash',
        ]);

        $this->controller->callConfirmPayment($s, 12345);

        $this->assertEquals(
            $before,
            CashTransaction::count(),
            'No new CashTransaction must be written when fx_presentation is absent'
        );
    }

    // ── FX/canonical path preserved ───────────────────────────────────────────

    /** @test */
    public function fx_path_in_select_guest_still_works_when_prepare_succeeds(): void
    {
        Beds24Booking::factory()->create([
            'beds24_booking_id' => 'B_FX_OK',
            'booking_status'    => 'confirmed',
            'property_id'       => '41097',
            'total_amount'      => 100.0,
        ]);

        $presentation = PaymentPresentation::fromArray([
            'beds24_booking_id' => 'B_FX_OK',
            'sync_id'           => 1,
            'daily_rate_id'     => 1,
            'guest_name'        => 'Test Guest',
            'arrival_date'      => now()->addDay()->toDateString(),
            'uzs_presented'     => 1_280_000,
            'eur_presented'     => 92,
            'rub_presented'     => 9200,
            'fx_rate_date'      => now()->format('d.m.Y'),
            'bot_session_id'    => 'sess-ok',
            'presented_at'      => now()->toIso8601String(),
        ]);

        $this->botPaymentServiceMock
            ->method('preparePayment')
            ->willReturn($presentation);

        $s = $this->makeSession(['shift_id' => $this->shiftId, '_live_guests' => []]);

        $this->controller->callSelectGuest($s, 12345, 'guest_B_FX_OK');

        $this->assertEquals('payment_fx_currency', $s->capturedState,
            'FX success path must advance session to payment_fx_currency state');
        $this->assertStringNotContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages)
        );
    }

    // ── (D) selectCur: FX presentedAmountFor() throws ────────────────────────────

    /** @test */
    public function select_cur_with_valid_fx_presentation_advances_to_payment_fx_amount(): void
    {
        $fxPresentation = $this->makeFxPresentation();

        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => $fxPresentation,
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertEquals(
            'payment_fx_amount',
            $s->capturedState,
            'Valid FX path must advance session to payment_fx_amount'
        );
        $this->assertStringNotContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages)
        );
    }

    /** @test */
    public function select_cur_with_corrupted_fx_presentation_is_blocked_immediately(): void
    {
        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            // corrupt presentation — fromArray() will fail or presentedAmountFor() will throw
            'fx_presentation' => ['bad_key' => 'bad_value'],
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertStringContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages),
            'Corrupted FX presentation must show block message'
        );
    }

    /** @test */
    public function select_cur_with_corrupted_fx_presentation_does_not_advance_session(): void
    {
        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => ['bad_key' => 'bad_value'],
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertNotEquals(
            'payment_method',
            $s->capturedState,
            'Session must not advance to payment_method when FX presentation is corrupted'
        );
        $this->assertNotEquals(
            'payment_fx_amount',
            $s->capturedState,
            'Session must not advance to payment_fx_amount when FX presentation is corrupted'
        );
    }

    /** @test */
    public function select_cur_without_fx_presentation_is_blocked_by_defence_in_depth(): void
    {
        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => null,
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertStringContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages),
            'Missing FX presentation must show block message (defence-in-depth)'
        );
    }

    /** @test */
    public function select_cur_without_fx_presentation_does_not_advance_to_payment_method(): void
    {
        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => null,
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertNotEquals(
            'payment_method',
            $s->capturedState,
            'Session must not reach payment_method state when FX is absent'
        );
    }

    /** @test */
    public function select_cur_without_fx_presentation_writes_no_cash_transaction(): void
    {
        $before = CashTransaction::count();

        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => null,
        ]);

        $this->controller->callSelectCur($s, 12345, 'cur_UZS');

        $this->assertEquals(
            $before,
            CashTransaction::count(),
            'No CashTransaction must be written when FX is absent in selectCur()'
        );
    }

    /** @test */
    public function confirm_payment_with_fx_presentation_uses_bot_service_not_legacy(): void
    {
        $mockTx = $this->createMock(CashTransaction::class);
        $this->botPaymentServiceMock
            ->expects($this->once())
            ->method('recordPayment')
            ->willReturn($mockTx);

        $s = $this->makeSession([
            'shift_id'        => $this->shiftId,
            'fx_presentation' => $this->makeFxPresentation(),
            'currency'        => 'UZS',
            'amount'          => 1_280_000,
            'method'          => 'cash',
            'override_reason' => null,
        ]);

        $this->controller->callConfirmPayment($s, 12345);

        // No block message — payment was processed via FX path
        $this->assertStringNotContainsString(
            'Курсы ФX недоступны',
            implode(' ', $this->controller->sentMessages)
        );
    }
}

// ── Testable subclass ─────────────────────────────────────────────────────────

class TestableLegacyBlockController extends CashierBotController
{
    public array $sentMessages = [];

    protected function send(int $chatId, string $text, mixed $kb = null, string $type = 'reply'): void
    {
        $this->sentMessages[] = $text;
    }

    protected function showMainMenu(int $chatId, $session): mixed
    {
        return null;
    }

    protected function alertOwnerOnError(string $context, \Throwable $e, ?int $userId = null): void {}

    protected function failCallback(string $callbackId, string $reason = ''): void {}

    protected function succeedCallback(string $callbackId): void {}

    public function callSelectGuest($s, int $chatId, string $data): mixed
    {
        return $this->selectGuest($s, $chatId, $data);
    }

    public function callSelectCur($s, int $chatId, string $data): mixed
    {
        return $this->selectCur($s, $chatId, $data);
    }

    public function callConfirmPayment($s, int $chatId, string $callbackId = ''): mixed
    {
        return $this->confirmPayment($s, $chatId, $callbackId);
    }
}
