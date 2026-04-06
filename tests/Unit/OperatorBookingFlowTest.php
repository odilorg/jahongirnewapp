<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\OperatorBookingSession;
use App\Services\OperatorBookingFlow;
use App\Services\WebsiteBookingService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for OperatorBookingFlow state machine.
 *
 * WebsiteBookingService is mocked — no real booking creation.
 * OperatorBookingSession is injected via a protected subclass override.
 * DB::table('tours') is mocked for the tour selection step.
 */
class OperatorBookingFlowTest extends TestCase
{
    private WebsiteBookingService $bookingService;
    private OperatorBookingFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = Mockery::mock(WebsiteBookingService::class);
        $this->flow = new OperatorBookingFlow($this->bookingService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── /newbooking command ──────────────────────────────────────────────────

    #[Test]
    public function newbooking_command_returns_tour_selection_keyboard(): void
    {
        $session = $this->mockSession('idle');
        $session->shouldReceive('reset')->once();
        $session->shouldReceive('setState')->with('select_tour')->once();

        // Mock DB tour query
        DB::shouldReceive('table')->with('tours')->andReturnSelf();
        DB::shouldReceive('select')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([
            (object) ['id' => 1, 'title' => 'Bukhara Full-Day Guided Tour'],
            (object) ['id' => 4, 'title' => 'Yurt Camp Group Tour'],
        ]));

        $response = $this->flow->handle('12345', '/newbooking', null);

        $this->assertStringContainsString('New manual booking', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
        $this->assertArrayHasKey('inline_keyboard', $response['reply_markup']);
        // Should have 2 tour buttons + 1 cancel button
        $this->assertCount(3, $response['reply_markup']['inline_keyboard']);
    }

    // ── /cancel at any step ──────────────────────────────────────────────────

    #[Test]
    public function cancel_command_resets_session_at_any_step(): void
    {
        $session = $this->mockSession('enter_date');
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', '/cancel', null);

        $this->assertStringContainsString('cancelled', $response['text']);
    }

    #[Test]
    public function cancel_callback_resets_session(): void
    {
        $session = $this->mockSession('enter_name');
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', null, 'cancel');

        $this->assertStringContainsString('cancelled', $response['text']);
    }

    // ── Expired session ──────────────────────────────────────────────────────

    #[Test]
    public function expired_session_resets_and_prompts_restart(): void
    {
        $session = $this->mockSession('enter_date', expired: true);
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', 'some text', null);

        $this->assertStringContainsString('expired', $response['text']);
    }

    // ── Date validation ──────────────────────────────────────────────────────

    #[Test]
    public function invalid_date_format_returns_error(): void
    {
        $this->mockSession('enter_date');

        $response = $this->flow->handle('12345', 'not-a-date', null);

        $this->assertStringContainsString('Invalid date', $response['text']);
    }

    #[Test]
    public function past_date_returns_error(): void
    {
        $this->mockSession('enter_date');

        $response = $this->flow->handle('12345', '2020-01-01', null);

        $this->assertStringContainsString('future', $response['text']);
    }

    #[Test]
    public function valid_future_date_advances_to_adults_step(): void
    {
        $session = $this->mockSession('enter_date');
        $session->shouldReceive('setData')->with('date', '2026-12-01')->once();
        $session->shouldReceive('setState')->with('enter_adults')->once();

        $response = $this->flow->handle('12345', '2026-12-01', null);

        $this->assertStringContainsString('adults', strtolower($response['text']));
    }

    // ── Adults validation ────────────────────────────────────────────────────

    #[Test]
    public function zero_adults_returns_error(): void
    {
        $this->mockSession('enter_adults');

        $response = $this->flow->handle('12345', '0', null);

        $this->assertStringContainsString('valid number of adults', $response['text']);
    }

    #[Test]
    public function non_numeric_adults_returns_error(): void
    {
        $this->mockSession('enter_adults');

        $response = $this->flow->handle('12345', 'lots', null);

        $this->assertStringContainsString('valid number of adults', $response['text']);
    }

    #[Test]
    public function valid_adults_advances_to_children_step(): void
    {
        $session = $this->mockSession('enter_adults');
        $session->shouldReceive('setData')->with('adults', 3)->once();
        $session->shouldReceive('setState')->with('enter_children')->once();

        $response = $this->flow->handle('12345', '3', null);

        $this->assertStringContainsString('children', strtolower($response['text']));
    }

    // ── Email validation ─────────────────────────────────────────────────────

    #[Test]
    public function invalid_email_returns_error(): void
    {
        $this->mockSession('enter_email');

        $response = $this->flow->handle('12345', 'not-an-email', null);

        $this->assertStringContainsString('valid email', $response['text']);
    }

    #[Test]
    public function valid_email_advances_to_phone_step(): void
    {
        $session = $this->mockSession('enter_email');
        $session->shouldReceive('setData')->with('guest_email', 'john@example.com')->once();
        $session->shouldReceive('setState')->with('enter_phone')->once();

        $response = $this->flow->handle('12345', 'John@Example.COM', null);

        // Email normalised to lowercase
        $this->assertStringContainsString('john@example.com', $response['text']);
    }

    // ── Hotel skip ───────────────────────────────────────────────────────────

    #[Test]
    public function hotel_skip_callback_advances_to_confirm(): void
    {
        $session = $this->mockSession('enter_hotel');
        $session->shouldReceive('setData')->with('hotel', null)->once();
        $session->shouldReceive('setState')->with('confirm')->once();

        // data property accessed in buildConfirmPrompt
        $session->data = [
            'tour_name'   => 'Yurt Camp Group Tour',
            'date'        => '2026-06-01',
            'adults'      => 2,
            'children'    => 0,
            'guest_name'  => 'John Doe',
            'guest_email' => 'john@example.com',
            'guest_phone' => '+998901234567',
            'hotel'       => null,
        ];

        $response = $this->flow->handle('12345', null, 'hotel:skip');

        $this->assertStringContainsString('Booking summary', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    // ── Confirm: success ─────────────────────────────────────────────────────

    #[Test]
    public function confirm_yes_creates_booking_and_shows_action_menu(): void
    {
        $booking = new Booking();
        $booking->booking_number = 'BOOK-2026-999';

        $this->bookingService
            ->shouldReceive('createFromWebsite')
            ->once()
            ->andReturn(['booking' => $booking, 'created' => true]);

        $session = $this->mockSession('confirm');
        $session->shouldReceive('toBookingData')->once()->andReturn([
            'tour' => 'Yurt Camp Group Tour', 'name' => 'John Doe',
            'email' => 'john@example.com', 'phone' => '+998901234567',
            'hotel' => null, 'date' => '2026-06-01',
            'adults' => 2, 'children' => 0, 'tour_code' => null,
        ]);
        // On success: store active_booking_id and switch to action menu (no reset)
        $session->shouldReceive('setData')->with('active_booking_id', Mockery::any())->once();
        $session->shouldReceive('setState')->with('booking_actions')->once();

        $response = $this->flow->handle('12345', null, 'confirm:yes');

        $this->assertStringContainsString('BOOK-2026-999', $response['text']);
        $this->assertStringContainsString('created', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    #[Test]
    public function confirm_cancel_callback_cancels_booking(): void
    {
        $session = $this->mockSession('confirm');
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', null, 'cancel');

        $this->assertStringContainsString('cancelled', $response['text']);
    }

    #[Test]
    public function confirm_yes_when_duplicate_shows_existing_booking(): void
    {
        $booking = new Booking();
        $booking->booking_number = 'BOOK-2026-087';

        $this->bookingService
            ->shouldReceive('createFromWebsite')
            ->once()
            ->andReturn(['booking' => $booking, 'created' => false]);

        $session = $this->mockSession('confirm');
        $session->shouldReceive('toBookingData')->once()->andReturn([
            'tour' => 'Yurt Camp Group Tour', 'name' => 'John Doe',
            'email' => 'john@example.com', 'phone' => '+998901234567',
            'hotel' => null, 'date' => '2026-06-01',
            'adults' => 2, 'children' => 0, 'tour_code' => null,
        ]);
        // Duplicate: also stores booking_id and switches to action menu (no reset)
        $session->shouldReceive('setData')->with('active_booking_id', Mockery::any())->once();
        $session->shouldReceive('setState')->with('booking_actions')->once();

        $response = $this->flow->handle('12345', null, 'confirm:yes');

        $this->assertStringContainsString('BOOK-2026-087', $response['text']);
        $this->assertStringContainsString('already exists', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    #[Test]
    public function confirm_yes_when_service_throws_returns_error_message(): void
    {
        $this->bookingService
            ->shouldReceive('createFromWebsite')
            ->once()
            ->andThrow(new \RuntimeException("Tour 'XYZ' could not be matched"));

        $session = $this->mockSession('confirm');
        $session->shouldReceive('toBookingData')->once()->andReturn([
            'tour' => 'XYZ', 'name' => 'John', 'email' => 'j@example.com',
            'phone' => '+1', 'hotel' => null, 'date' => '2026-06-01',
            'adults' => 1, 'children' => 0, 'tour_code' => null,
        ]);
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', null, 'confirm:yes');

        $this->assertStringContainsString('Failed', $response['text']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function mockSession(string $state, bool $expired = false): Mockery\MockInterface
    {
        $session = Mockery::mock(OperatorBookingSession::class)->makePartial();
        $session->shouldAllowMockingProtectedMethods();
        $session->state    = $state;
        $session->chat_id  = '12345';
        $session->shouldReceive('isExpired')->andReturn($expired);

        // Replace the flow with a subclass that injects our mock session
        $this->flow = new class ($this->bookingService, $session) extends OperatorBookingFlow {
            public function __construct(
                WebsiteBookingService $bookingService,
                private OperatorBookingSession $mockSession,
            ) {
                parent::__construct($bookingService);
            }

            protected function getOrCreateSession(string $chatId): OperatorBookingSession
            {
                return $this->mockSession;
            }
        };

        return $session;
    }
}
