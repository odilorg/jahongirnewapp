<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\DTO\PaymentPresentation;
use App\Http\Controllers\CashierBotController;
use App\Models\Beds24Booking;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\BotPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * fix/cashier-bot-allow-usd-collection — keyboard visibility regression.
 *
 * Verifies that selectGuest() includes the USD button when usdPresented > 0
 * and hides it when usdPresented == 0 (so cashier never sees a "USD: 0.00"
 * choice that BotPaymentService would reject downstream).
 */
final class UsdCurrencyButtonVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private BotPaymentService $botPaymentMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->botPaymentMock = Mockery::mock(BotPaymentService::class);
        $this->app->bind(BotPaymentService::class, fn () => $this->botPaymentMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_usd_button_appears_when_usd_presented_is_positive(): void
    {
        $kb = $this->renderKeyboard(usdPresented: 67);

        $this->assertContains('cur_USD', $this->callbackDataValues($kb),
            'USD button must appear when usdPresented > 0');
    }

    public function test_usd_button_hidden_when_usd_presented_is_zero(): void
    {
        // usdPresented=0 means the FX engine couldn't compute a USD amount
        // (e.g. odd booking shape). Other currencies still positive → keyboard
        // shows them but skips USD.
        $kb = $this->renderKeyboard(
            usdPresented: 0,
            uzsPresented: 820_000,
            eurPresented: 60,
            rubPresented: 6_200,
        );

        $cbData = $this->callbackDataValues($kb);
        $this->assertNotContains('cur_USD', $cbData,
            'USD button must be hidden when usdPresented == 0 (no "USD: 0.00" UX leak)');
        $this->assertContains('cur_UZS', $cbData, 'UZS button still appears when other currencies are positive');
    }

    public function test_uzs_button_always_present_when_invoice_balance_positive(): void
    {
        $kb = $this->renderKeyboard(usdPresented: 67);

        $this->assertContains('cur_UZS', $this->callbackDataValues($kb),
            'UZS button is the booking-equivalent and must always appear');
    }

    public function test_eur_and_rub_buttons_appear_when_positive(): void
    {
        $kb = $this->renderKeyboard(usdPresented: 67, eurPresented: 60, rubPresented: 6_200);

        $cbData = $this->callbackDataValues($kb);
        $this->assertContains('cur_EUR', $cbData);
        $this->assertContains('cur_RUB', $cbData);
        $this->assertContains('cur_USD', $cbData);
    }

    /**
     * Drive selectGuest() with a stubbed BotPaymentService and capture the
     * inline keyboard from the test-seam controller.
     */
    private function renderKeyboard(
        int $usdPresented,
        int $uzsPresented = 820_000,
        int $eurPresented = 60,
        int $rubPresented = 6_200,
    ): array {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user   = User::factory()->create();
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $bid     = 'B-' . uniqid();
        $booking = Beds24Booking::create([
            'beds24_booking_id' => $bid,
            'property_id'       => '41097',
            'guest_name'        => 'John Doe',
            'arrival_date'      => now()->toDateString(),
            'departure_date'    => now()->addDay()->toDateString(),
            // invoice_balance gates the FX-presentation render; keep positive
            // so keyboard branch fires regardless of usdPresented.
            'invoice_balance'   => 1,
            'total_amount'      => 1,
            'booking_status'    => 'confirmed',
            'channel'           => 'direct',
        ]);

        $session = TelegramPosSession::create([
            'chat_id' => 999_777_555,
            'user_id' => $user->id,
            'state'   => 'payment_guest_select',
            'data'    => [
                'shift_id' => $shift->id,
                '_live_guests' => [],
            ],
        ]);

        $presentation = new PaymentPresentation(
            beds24BookingId:     $bid,
            syncId:              1,
            dailyExchangeRateId: 1,
            guestName:           'John Doe',
            arrivalDate:         now()->toDateString(),
            uzsPresented:        $uzsPresented,
            eurPresented:        $eurPresented,
            rubPresented:        $rubPresented,
            fxRateDate:          now()->format('d.m.Y'),
            botSessionId:        'test-sess',
            presentedAt:         Carbon::now(),
            usdPresented:        $usdPresented,
        );

        $this->botPaymentMock->shouldReceive('preparePayment')
            ->andReturn($presentation);

        $controller = $this->app->make(CapturingCashierBotController::class);
        $controller->callSelectGuest($session, $session->chat_id, "guest_{$bid}");

        return $controller->lastKeyboard ?? [];
    }

    private function callbackDataValues(array $kb): array
    {
        $rows = $kb['inline_keyboard'] ?? [];
        $values = [];
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                if (isset($btn['callback_data'])) {
                    $values[] = $btn['callback_data'];
                }
            }
        }
        return $values;
    }
}

/**
 * Test seam — captures the keyboard passed to send() so the test can assert
 * on its contents without parsing Telegram API output.
 */
class CapturingCashierBotController extends CashierBotController
{
    public ?array $lastKeyboard = null;
    public array $sentMessages = [];

    public function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply'): void
    {
        $this->sentMessages[] = $text;
        if ($kb !== null && isset($kb['inline_keyboard'])) {
            $this->lastKeyboard = $kb;
        }
    }

    public function callSelectGuest($s, int $chatId, string $data): mixed
    {
        return $this->selectGuest($s, $chatId, $data);
    }
}
