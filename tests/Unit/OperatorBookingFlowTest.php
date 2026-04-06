<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BotOperator;
use App\Models\Booking;
use App\Models\OperatorBookingSession;
use App\Services\BookingBrowseService;
use App\Services\BookingOpsService;
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
    private BookingBrowseService  $browseService;
    private OperatorBookingFlow   $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = Mockery::mock(WebsiteBookingService::class);
        $this->browseService  = Mockery::mock(BookingBrowseService::class);
        $this->flow           = new OperatorBookingFlow($this->bookingService);
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
        // /newbooking clears data via update() then stepSelectTour sets state
        $session->shouldReceive('update')->once();
        $session->shouldReceive('setState')->with('select_tour')->once();

        // Mock DB tour query
        DB::shouldReceive('table')->with('tours')->andReturnSelf();
        DB::shouldReceive('select')->andReturnSelf();
        DB::shouldReceive('orderBy')->andReturnSelf();
        DB::shouldReceive('get')->andReturn(collect([
            (object) ['id' => 1, 'title' => 'Bukhara Full-Day Guided Tour'],
            (object) ['id' => 4, 'title' => 'Yurt Camp Group Tour'],
        ]));

        $response = $this->flow->handle('12345', '/newbooking', null, $this->adminOperator());

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

        $response = $this->flow->handle('12345', '/cancel', null, $this->adminOperator());

        $this->assertStringContainsString('cancelled', $response['text']);
    }

    #[Test]
    public function cancel_callback_resets_session(): void
    {
        $session = $this->mockSession('enter_name');
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', null, 'cancel', $this->adminOperator());

        $this->assertStringContainsString('cancelled', $response['text']);
    }

    // ── Expired session ──────────────────────────────────────────────────────

    #[Test]
    public function expired_session_resets_and_prompts_restart(): void
    {
        $session = $this->mockSession('enter_date', expired: true);
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', 'some text', null, $this->adminOperator());

        $this->assertStringContainsString('expired', $response['text']);
    }

    // ── Date validation ──────────────────────────────────────────────────────

    #[Test]
    public function invalid_date_format_returns_error(): void
    {
        $this->mockSession('enter_date');

        $response = $this->flow->handle('12345', 'not-a-date', null, $this->adminOperator());

        $this->assertStringContainsString('Invalid date', $response['text']);
    }

    #[Test]
    public function past_date_returns_error(): void
    {
        $this->mockSession('enter_date');

        $response = $this->flow->handle('12345', '2020-01-01', null, $this->adminOperator());

        $this->assertStringContainsString('future', $response['text']);
    }

    #[Test]
    public function valid_future_date_advances_to_adults_step(): void
    {
        $session = $this->mockSession('enter_date');
        $session->shouldReceive('setData')->with('date', '2026-12-01')->once();
        $session->shouldReceive('setState')->with('enter_adults')->once();

        $response = $this->flow->handle('12345', '2026-12-01', null, $this->adminOperator());

        $this->assertStringContainsString('adults', strtolower($response['text']));
    }

    // ── Adults validation ────────────────────────────────────────────────────

    #[Test]
    public function zero_adults_returns_error(): void
    {
        $this->mockSession('enter_adults');

        $response = $this->flow->handle('12345', '0', null, $this->adminOperator());

        $this->assertStringContainsString('valid number of adults', $response['text']);
    }

    #[Test]
    public function non_numeric_adults_returns_error(): void
    {
        $this->mockSession('enter_adults');

        $response = $this->flow->handle('12345', 'lots', null, $this->adminOperator());

        $this->assertStringContainsString('valid number of adults', $response['text']);
    }

    #[Test]
    public function valid_adults_advances_to_children_step(): void
    {
        $session = $this->mockSession('enter_adults');
        $session->shouldReceive('setData')->with('adults', 3)->once();
        $session->shouldReceive('setState')->with('enter_children')->once();

        $response = $this->flow->handle('12345', '3', null, $this->adminOperator());

        $this->assertStringContainsString('children', strtolower($response['text']));
    }

    // ── Email validation ─────────────────────────────────────────────────────

    #[Test]
    public function invalid_email_returns_error(): void
    {
        $this->mockSession('enter_email');

        $response = $this->flow->handle('12345', 'not-an-email', null, $this->adminOperator());

        $this->assertStringContainsString('valid email', $response['text']);
    }

    #[Test]
    public function valid_email_advances_to_phone_step(): void
    {
        $session = $this->mockSession('enter_email');
        $session->shouldReceive('setData')->with('guest_email', 'john@example.com')->once();
        $session->shouldReceive('setState')->with('enter_phone')->once();

        $response = $this->flow->handle('12345', 'John@Example.COM', null, $this->adminOperator());

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

        $response = $this->flow->handle('12345', null, 'hotel:skip', $this->adminOperator());

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
        // On success: single update() call stores booking_id and state together
        $session->shouldReceive('update')->with(Mockery::on(fn ($args) =>
            ($args['state'] ?? null) === 'booking_actions'
        ))->once();

        $response = $this->flow->handle('12345', null, 'confirm:yes', $this->adminOperator());

        $this->assertStringContainsString('BOOK-2026-999', $response['text']);
        $this->assertStringContainsString('created', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    #[Test]
    public function confirm_cancel_callback_cancels_booking(): void
    {
        $session = $this->mockSession('confirm');
        $session->shouldReceive('reset')->once();

        $response = $this->flow->handle('12345', null, 'cancel', $this->adminOperator());

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
        // Duplicate: single update() call stores booking_id and state together
        $session->shouldReceive('update')->with(Mockery::on(fn ($args) =>
            ($args['state'] ?? null) === 'booking_actions'
        ))->once();

        $response = $this->flow->handle('12345', null, 'confirm:yes', $this->adminOperator());

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

        $response = $this->flow->handle('12345', null, 'confirm:yes', $this->adminOperator());

        $this->assertStringContainsString('Failed', $response['text']);
    }

    // ── /bookings command ─────────────────────────────────────────────────────

    #[Test]
    public function bookings_command_shows_paginated_list(): void
    {
        $session = $this->mockSession('idle', browseService: $this->browseService);
        $session->shouldReceive('update')->once();  // stores browse state
        $session->shouldReceive('setState')->andReturn(); // buildBookingList -> setState
        $session->shouldReceive('getData')->with('browse_show_cancelled', false)->andReturn(false);
        $session->shouldReceive('getData')->with('browse_page', 1)->andReturn(1);
        $session->shouldReceive('getData')->with('browse_page')->andReturn(1);
        $session->shouldReceive('getData')->with('browse_show_cancelled')->andReturn(false);
        $session->shouldReceive('setData')->andReturn();

        $this->browseService->shouldReceive('paginate')
            ->with(1, false)
            ->once()
            ->andReturn([
                'items' => [
                    ['id' => 10, 'label' => 'BOOK-2026-089 | 22 May | John Doe ⏳'],
                    ['id' => 11, 'label' => 'BOOK-2026-090 | 03 May | Blake Kim ⏳'],
                ],
                'page'  => 1,
                'pages' => 1,
                'total' => 2,
            ]);

        $response = $this->flow->handle('12345', '/bookings', null, $this->adminOperator());

        $this->assertStringContainsString('Upcoming bookings', $response['text']);
        $this->assertStringContainsString('2 total', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
        // 2 booking rows + 1 control row = 3 rows
        $this->assertCount(3, $response['reply_markup']['inline_keyboard']);
    }

    #[Test]
    public function bookings_command_shows_empty_state_when_no_results(): void
    {
        $session = $this->mockSession('idle', browseService: $this->browseService);
        $session->shouldReceive('update')->once();
        $session->shouldReceive('setState')->andReturn();
        $session->shouldReceive('getData')->andReturn(null);
        $session->shouldReceive('setData')->andReturn();

        $this->browseService->shouldReceive('paginate')
            ->with(1, false)
            ->once()
            ->andReturn(['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0]);

        $response = $this->flow->handle('12345', '/bookings', null, $this->adminOperator());

        $this->assertStringContainsString('No upcoming', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    #[Test]
    public function brs_pg_callback_navigates_to_next_page(): void
    {
        $session = $this->mockSession('browse_list', browseService: $this->browseService);
        $session->shouldReceive('setData')->with('browse_page', 2)->once();
        $session->shouldReceive('setState')->andReturn();
        $session->shouldReceive('getData')->with('browse_page')->andReturn(2);
        $session->shouldReceive('getData')->with('browse_show_cancelled')->andReturn(false);
        $session->shouldReceive('getData')->with('browse_show_cancelled', false)->andReturn(false);
        $session->shouldReceive('getData')->with('browse_page', 1)->andReturn(2);
        $session->shouldReceive('setData')->andReturn();

        $this->browseService->shouldReceive('paginate')
            ->with(2, false)
            ->once()
            ->andReturn([
                'items' => [['id' => 15, 'label' => 'BOOK-2026-095 | 10 Jun | Jane ✅']],
                'page'  => 2,
                'pages' => 2,
                'total' => 11,
            ]);

        $response = $this->flow->handle('12345', null, 'brs:pg:2', $this->adminOperator());

        $this->assertStringContainsString('Page 2/2', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    // ── Edit menu ────────────────────────────────────────────────────────────

    #[Test]
    public function action_menu_includes_edit_booking_button_for_pending_booking(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_number = 'BOOK-2026-001';
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:menu', $this->adminOperator());

        $encoded = json_encode($response['reply_markup']['inline_keyboard']);
        $this->assertStringContainsString('ops:edit', $encoded);
        $this->assertStringContainsString('Edit booking', $encoded);
    }

    #[Test]
    public function ops_edit_callback_opens_edit_menu(): void
    {
        $booking         = new Booking();
        $booking->id     = 1;
        $booking->booking_number  = 'BOOK-2026-001';
        $booking->booking_status  = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:edit', $this->adminOperator());

        $this->assertStringContainsString('Edit', $response['text']);
        $encoded = json_encode($response['reply_markup']['inline_keyboard']);
        $this->assertStringContainsString('ops:edit_name',  $encoded);
        $this->assertStringContainsString('ops:edit_phone', $encoded);
        $this->assertStringContainsString('ops:edit_email', $encoded);
        $this->assertStringContainsString('ops:edit_date',  $encoded);
        $this->assertStringContainsString('ops:edit_pax',   $encoded);
        $this->assertStringContainsString('ops:edit_notes', $encoded);
    }

    #[Test]
    public function ops_edit_name_callback_sets_state_and_shows_prompt(): void
    {
        $booking         = new Booking();
        $booking->id     = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);
        $session->shouldReceive('setState')->with('edit_name_input')->once();

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:edit_name', $this->adminOperator());

        $this->assertStringContainsString('full name', strtolower($response['text']));
        $encoded = json_encode($response['reply_markup']['inline_keyboard']);
        $this->assertStringContainsString('ops:edit', $encoded);
    }

    #[Test]
    public function edit_date_prompt_warns_about_notification_reschedule(): void
    {
        $booking         = new Booking();
        $booking->id     = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);
        $session->shouldReceive('setState')->with('edit_date_input')->once();

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:edit_date', $this->adminOperator());

        $this->assertStringContainsString('reschedule', strtolower($response['text']));
    }

    #[Test]
    public function edit_email_input_rejects_invalid_email(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_status = 'confirmed';

        $session = $this->mockSession('edit_email_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', 'not-an-email', null, $this->adminOperator());

        $this->assertStringContainsString('valid email', strtolower($response['text']));
    }

    #[Test]
    public function edit_date_input_rejects_past_date(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('edit_date_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', '2020-01-01', null, $this->adminOperator());

        $this->assertStringContainsString('future', strtolower($response['text']));
    }

    #[Test]
    public function edit_date_input_rejects_invalid_format(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('edit_date_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', '15/06/2026', null, $this->adminOperator());

        $this->assertStringContainsString('YYYY-MM-DD', $response['text']);
    }

    #[Test]
    public function edit_pax_input_rejects_zero(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('edit_pax_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', '0', null, $this->adminOperator());

        $this->assertStringContainsString('1 and 50', $response['text']);
    }

    #[Test]
    public function edit_pax_input_rejects_over_fifty(): void
    {
        $booking        = new Booking();
        $booking->id    = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('edit_pax_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);

        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', '51', null, $this->adminOperator());

        $this->assertStringContainsString('1 and 50', $response['text']);
    }

    // ── Auth / permission tests ──────────────────────────────────────────────

    #[Test]
    public function null_operator_cannot_confirm_booking(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        // No operator passed → should be denied
        $response = $this->flow->handle('12345', null, 'ops:confirm');

        $this->assertStringContainsString('🚫', $response['text']);
        $this->assertArrayNotHasKey('reply_markup', $response);
    }

    #[Test]
    public function operator_role_cannot_confirm_booking(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:confirm', $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $response['text']);
        $this->assertStringContainsString('managers', strtolower($response['text']));
    }

    #[Test]
    public function operator_role_cannot_cancel_booking(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:cancel_ask', $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $response['text']);
    }

    #[Test]
    public function operator_role_cannot_set_price(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:price', $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $response['text']);
    }

    #[Test]
    public function manager_role_can_confirm_booking(): void
    {
        // Manager CAN confirm — only tests the permission check, not the full confirm flow
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';
        $booking->booking_number = 'BOOK-TEST';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        // opsConfirm calls $this->opsService->confirm() → BookingOpsService
        // The booking refresh() will fail since it's a plain Booking instance,
        // but the perm check passes. We verify 🚫 is NOT in the response text.
        $response = $this->flow->handle('12345', null, 'ops:confirm', $this->makeOperator('manager'));

        // If permission denied, response contains '🚫'. Manager should NOT see that.
        $this->assertStringNotContainsString('🚫', $response['text']);
    }

    #[Test]
    public function viewer_role_action_menu_shows_no_edit_or_manage_buttons(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_number = 'BOOK-2026-001';
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:menu', $this->makeOperator('viewer'));

        $encoded = json_encode($response['reply_markup']['inline_keyboard'] ?? []);
        $this->assertStringNotContainsString('ops:edit',    $encoded);
        $this->assertStringNotContainsString('ops:confirm', $encoded);
        $this->assertStringNotContainsString('ops:cancel',  $encoded);
        $this->assertStringNotContainsString('ops:price',   $encoded);
    }

    #[Test]
    public function operator_role_action_menu_shows_edit_but_not_price_or_cancel(): void
    {
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_number = 'BOOK-2026-001';
        $booking->booking_status = 'pending';

        $session = $this->mockSession('booking_actions');
        $session->shouldReceive('getData')->andReturn(null);
        $this->patchActiveBooking($session, $booking);

        $response = $this->flow->handle('12345', null, 'ops:menu', $this->makeOperator('operator'));

        $encoded = json_encode($response['reply_markup']['inline_keyboard'] ?? []);
        $this->assertStringContainsString('ops:edit',    $encoded, 'operator should see edit');
        $this->assertStringContainsString('ops:drivers', $encoded, 'operator should see assign driver');
        $this->assertStringNotContainsString('ops:confirm', $encoded, 'operator should NOT see confirm');
        $this->assertStringNotContainsString('ops:price',   $encoded, 'operator should NOT see set price');
    }

    #[Test]
    public function edit_input_step_re_checks_permission_if_role_changed(): void
    {
        // Start with a viewer in the edit_name_input state (simulating a role downgrade mid-flow)
        $booking = new Booking();
        $booking->id             = 1;
        $booking->booking_status = 'pending';

        $session = $this->mockSession('edit_name_input');
        $session->shouldReceive('getData')->with('active_booking_id')->andReturn(1);
        $session->shouldReceive('getData')->andReturn(null);
        $session->shouldReceive('setState')->with('booking_actions')->once(); // reset on deny

        $this->patchActiveBooking($session, $booking);

        // Viewer tries to submit text input for the name edit
        $response = $this->flow->handle('12345', 'New Name', null, $this->makeOperator('viewer'));

        $this->assertStringContainsString('🚫', $response['text']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build an in-memory BotOperator without touching the database.
     */
    private function adminOperator(): BotOperator
    {
        return $this->makeOperator('admin');
    }

    private function makeOperator(string $role): BotOperator
    {
        $op = new BotOperator();
        $op->telegram_user_id = '12345';
        $op->role             = $role;
        $op->is_active        = true;
        return $op;
    }

    /**
     * Replace the OperatorBookingFlow with an anonymous subclass that returns
     * $booking from activeBooking(). Used only by edit-flow tests.
     *
     * Requires activeBooking() to be protected in OperatorBookingFlow.
     */
    private function patchActiveBooking(Mockery\MockInterface $session, Booking $booking): void
    {
        $this->flow = new class (
            $this->bookingService,
            new BookingOpsService(),
            new BookingBrowseService(),
            $session,
            $booking,
        ) extends OperatorBookingFlow {
            public function __construct(
                WebsiteBookingService $bs,
                BookingOpsService $os,
                BookingBrowseService $brs,
                private OperatorBookingSession $mockSession,
                private Booking $mockBooking,
            ) {
                parent::__construct($bs, $os, $brs);
            }

            protected function getOrCreateSession(string $chatId): OperatorBookingSession
            {
                return $this->mockSession;
            }

            protected function activeBooking(OperatorBookingSession $session): ?Booking
            {
                return $this->mockBooking;
            }
        };
    }

    private function mockSession(
        string $state,
        bool $expired = false,
        ?BookingBrowseService $browseService = null,
    ): Mockery\MockInterface {
        $session = Mockery::mock(OperatorBookingSession::class)->makePartial();
        $session->shouldAllowMockingProtectedMethods();
        $session->state    = $state;
        $session->chat_id  = '12345';
        $session->shouldReceive('isExpired')->andReturn($expired);

        $bs = $browseService ?? new BookingBrowseService();

        // Replace the flow with a subclass that injects our mock session and browse service
        $this->flow = new class (
            $this->bookingService,
            new BookingOpsService(),
            $bs,
            $session,
        ) extends OperatorBookingFlow {
            public function __construct(
                WebsiteBookingService $bookingService,
                BookingOpsService $opsService,
                BookingBrowseService $browseService,
                private OperatorBookingSession $mockSession,
            ) {
                parent::__construct($bookingService, $opsService, $browseService);
            }

            protected function getOrCreateSession(string $chatId): OperatorBookingSession
            {
                return $this->mockSession;
            }
        };

        return $session;
    }
}
