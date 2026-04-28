<?php

namespace Tests\Feature\CashierBot;

use App\DTO\PaymentPresentation;
use App\Http\Controllers\CashierBotController;
use App\Models\Beds24Booking;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\BotPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for on-demand booking import when a Beds24 booking is selected in the
 * cashier bot but is not yet in the local DB.
 *
 * Phase 5 fix: selectGuest() now calls importBookingFromLiveData() when $b is
 * null and live session data is available, so the FX presentation path can
 * proceed without waiting for the next webhook.
 *
 * Scenarios:
 *   (A) Local row already exists — import NOT called, happy path unchanged
 *   (B) Local row missing, live data present — imported, FX path enters
 *   (C) Local row missing, import DB error — payment blocked, error logged
 *   (D) Local row missing, no live data — payment blocked, warning logged
 */
class OnDemandBookingImportTest extends TestCase
{
    use RefreshDatabase;

    private TestableImportController $controller;
    private BotPaymentService        $botMock;
    private int                      $shiftId;
    private int                      $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botMock = $this->createMock(BotPaymentService::class);
        $this->app->bind(BotPaymentService::class, fn () => $this->botMock);
        $this->controller = $this->app->make(TestableImportController::class);

        $drawer = CashDrawer::create(['name' => 'Test Drawer', 'is_active' => true]);
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

    /** Minimal raw Beds24 API response as stored in session _live_guests. */
    private function makeLiveGuest(string $bid, array $overrides = []): array
    {
        return array_merge([
            'id'            => $bid,
            'firstName'     => 'Ivan',
            'lastName'      => 'Silkin',
            'arrival'       => today()->toDateString(),
            'departure'     => today()->addDays(3)->toDateString(),
            'numAdult'      => 2,
            'numChild'      => 0,
            'price'         => 150.00,
            'deposit'       => 0.00,
            'propertyId'    => 41097,
            'roomId'        => 1001,
            'roomName'      => '101',
            'status'        => 'confirmed',
            'rateDescription' => '2026-01-01 (Standard Rate) USD 150.00',
        ], $overrides);
    }

    /** Minimal fake session with shift and live guest stashed. */
    private function makeSession(string $bid, ?array $liveGuest, array $extra = []): object
    {
        $data = array_merge(['shift_id' => $this->shiftId], $extra);
        if ($liveGuest !== null) {
            $data['_live_guests'][$bid] = $liveGuest;
        }

        return new class($this->userId, $data) {
            public array $data;
            public int $user_id;
            public ?string $capturedState = null;

            public function __construct(int $userId, array $data)
            {
                $this->user_id = $userId;
                $this->data    = $data;
            }

            public function update(array $attrs): void
            {
                if (isset($attrs['data']))  $this->data = $attrs['data'];
                if (isset($attrs['state'])) $this->capturedState = $attrs['state'];
            }
        };
    }

    /** Make a valid PaymentPresentation the mock returns. */
    private function makePresentation(string $bid): PaymentPresentation
    {
        return PaymentPresentation::fromArray([
            'beds24_booking_id' => $bid,
            'sync_id'           => 1,
            'daily_rate_id'     => 1,
            'guest_name'        => 'Ivan Silkin',
            'arrival_date'      => today()->toDateString(),
            'uzs_presented'     => 1_920_000,
            'eur_presented'     => 140,
            'rub_presented'     => 14000,
            'fx_rate_date'      => today()->format('d.m.Y'),
            'bot_session_id'    => 'sess-test',
            'presented_at'      => now()->toIso8601String(),
        ]);
    }

    // ── (A) Local row exists — import NOT called ───────────────────────────────

    /** @test */
    public function existing_local_booking_takes_fx_path_without_import(): void
    {
        $bid = '99900001';
        Beds24Booking::factory()->create([
            'beds24_booking_id' => $bid,
            'booking_status'    => 'confirmed',
            'total_amount'      => 150.00,
        ]);

        $this->botMock
            ->expects($this->once())
            ->method('preparePayment')
            ->willReturn($this->makePresentation($bid));

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $this->assertStringNotContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
            'Happy path must not show the error message'
        );
    }

    /** @test */
    public function existing_local_booking_is_not_duplicated_by_import(): void
    {
        $bid = '99900002';
        Beds24Booking::factory()->create([
            'beds24_booking_id' => $bid,
            'booking_status'    => 'confirmed',
            'total_amount'      => 150.00,
        ]);

        $countBefore = Beds24Booking::where('beds24_booking_id', $bid)->count();

        $this->botMock->method('preparePayment')->willReturn($this->makePresentation($bid));

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $this->assertEquals($countBefore, Beds24Booking::where('beds24_booking_id', $bid)->count());
    }

    // ── (B) Local row missing, live data present — import, FX path enters ─────

    /** @test */
    public function api_only_booking_is_imported_and_fx_path_proceeds(): void
    {
        $bid = '99900003';
        $this->assertDatabaseMissing('beds24_bookings', ['beds24_booking_id' => $bid]);

        $this->botMock
            ->expects($this->once())
            ->method('preparePayment')
            ->willReturn($this->makePresentation($bid));

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        // Row must have been created
        $this->assertDatabaseHas('beds24_bookings', [
            'beds24_booking_id' => $bid,
            'guest_name'        => 'Ivan Silkin',
            'booking_status'    => 'confirmed',
        ]);

        // Error message must NOT be shown
        $this->assertStringNotContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
        );
    }

    /** @test */
    public function imported_booking_has_correct_amounts_from_live_data(): void
    {
        $bid = '99900004';
        $live = $this->makeLiveGuest($bid, ['price' => 200.00, 'deposit' => 50.00]);

        $this->botMock->method('preparePayment')->willReturn($this->makePresentation($bid));

        $s = $this->makeSession($bid, $live);
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $booking = Beds24Booking::where('beds24_booking_id', $bid)->first();
        $this->assertNotNull($booking);
        $this->assertEquals(200.00, (float) $booking->total_amount);
        $this->assertEquals(150.00, (float) $booking->invoice_balance); // 200 - 50
    }

    /** @test */
    public function import_is_idempotent_when_webhook_arrives_concurrently(): void
    {
        $bid = '99900005';
        // Simulate the webhook having created the row just before/during the import
        Beds24Booking::factory()->create([
            'beds24_booking_id' => $bid,
            'booking_status'    => 'confirmed',
            'total_amount'      => 150.00,
        ]);

        $this->botMock->method('preparePayment')->willReturn($this->makePresentation($bid));

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        // Only one row exists
        $this->assertEquals(1, Beds24Booking::where('beds24_booking_id', $bid)->count());
    }

    /** @test */
    public function import_logs_info_with_correct_context(): void
    {
        $bid = '99900006';

        $this->botMock->method('preparePayment')->willReturn($this->makePresentation($bid));

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context) use ($bid) {
                return str_contains($message, 'imported on-demand')
                    && ($context['beds24_booking_id'] ?? '') === $bid;
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");
    }

    /** @test */
    public function import_log_shows_action_created_for_new_booking(): void
    {
        $bid = '99900011';
        $this->botMock->method('preparePayment')->willReturn($this->makePresentation($bid));

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(function (string $msg, array $ctx) use ($bid) {
                return str_contains($msg, 'imported on-demand')
                    && ($ctx['beds24_booking_id'] ?? '') === $bid
                    && ($ctx['action'] ?? '') === 'created';
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");
    }

    /** @test */
    public function import_log_shows_action_updated_when_row_exists(): void
    {
        $bid = '99900014';
        // Row already exists (e.g. webhook arrived between the DB query and the import call)
        Beds24Booking::factory()->create([
            'beds24_booking_id' => $bid,
            'booking_status'    => 'confirmed',
            'total_amount'      => 100.00,
        ]);

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->withArgs(function (string $msg, array $ctx) use ($bid) {
                return str_contains($msg, 'imported on-demand')
                    && ($ctx['beds24_booking_id'] ?? '') === $bid
                    && ($ctx['action'] ?? '') === 'updated';
            });
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Call importBookingFromLiveData() directly (simulates the race condition path)
        $this->controller->callImportBookingFromLiveData($bid, $this->makeLiveGuest($bid));
    }

    /** @test */
    public function import_is_skipped_when_arrival_date_missing_from_live_data(): void
    {
        $bid = '99900012';
        // arrival_date is missing — import must return null, not create a junk row
        $live = $this->makeLiveGuest($bid, ['arrival' => null]);
        unset($live['arrival']);

        $s = $this->makeSession($bid, $live);
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        // No row must be created
        $this->assertDatabaseMissing('beds24_bookings', ['beds24_booking_id' => $bid]);

        // Operator sees the standard block message
        $this->assertStringContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
        );
    }

    /** @test */
    public function import_is_skipped_when_arrival_date_is_empty_string(): void
    {
        $bid = '99900013';
        $live = $this->makeLiveGuest($bid, ['arrival' => '']);

        $s = $this->makeSession($bid, $live);
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $this->assertDatabaseMissing('beds24_bookings', ['beds24_booking_id' => $bid]);
        $this->assertStringContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
        );
    }

    // ── (C) Local row missing, import fails → payment blocked ─────────────────

    /** @test */
    public function payment_is_blocked_when_import_fails_with_clear_message(): void
    {
        $bid = '99900007';

        // Simulate DB failure during import by using an ID that causes a DB error.
        // We mock the controller's import method to force failure.
        $this->controller->forceImportFailure = true;

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $this->assertStringContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
            'Must show block message when import fails'
        );
    }

    /** @test */
    public function prepare_payment_not_called_when_import_fails(): void
    {
        $bid = '99900008';
        $this->controller->forceImportFailure = true;

        $this->botMock->expects($this->never())->method('preparePayment');

        $s = $this->makeSession($bid, $this->makeLiveGuest($bid));
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");
    }

    // ── (D) Local row missing, no live data → payment blocked ─────────────────

    /** @test */
    public function payment_is_blocked_when_no_local_row_and_no_live_data(): void
    {
        $bid = '99900009';
        // No local booking, no _live_guests entry
        $s = $this->makeSession($bid, null);
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");

        $this->assertStringContainsString(
            'недоступны',
            implode(' ', $this->controller->sentMessages),
        );
    }

    /** @test */
    public function warning_is_logged_when_both_local_and_live_data_missing(): void
    {
        $bid = '99900010';

        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(function (string $message, array $context) use ($bid) {
                return str_contains($message, 'payment blocked')
                    && ($context['beds24_booking_id'] ?? '') === $bid
                    && ($context['live_data_present'] ?? true) === false;
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $s = $this->makeSession($bid, null);
        $this->controller->callSelectGuest($s, 12345, "guest_{$bid}");
    }
}

// ── Testable subclass ─────────────────────────────────────────────────────────

class TestableImportController extends CashierBotController
{
    public array $sentMessages = [];

    /** When true, importBookingFromLiveData() returns null to simulate failure. */
    public bool $forceImportFailure = false;

    public function send(int $chatId, string $text, mixed $kb = null, string $type = 'reply'): void
    {
        $this->sentMessages[] = $text;
    }

    public function showMainMenu(int $chatId, $session): mixed
    {
        return null;
    }

    protected function alertOwnerOnError(string $context, \Throwable $e, ?int $userId = null): void {}

    protected function failCallback(string $callbackId, string $reason = ''): void {}

    protected function succeedCallback(string $callbackId): void {}

    /** Override to allow simulated import failures. */
    protected function importBookingFromLiveData(string $bid, array $liveGuest): ?\App\Models\Beds24Booking
    {
        if ($this->forceImportFailure) {
            return null;
        }
        return parent::importBookingFromLiveData($bid, $liveGuest);
    }

    public function callSelectGuest($s, int $chatId, string $data): mixed
    {
        return $this->selectGuest($s, $chatId, $data);
    }

    public function callImportBookingFromLiveData(string $bid, array $liveGuest): ?\App\Models\Beds24Booking
    {
        return $this->importBookingFromLiveData($bid, $liveGuest);
    }
}
