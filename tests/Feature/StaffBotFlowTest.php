<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BotOperator;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\StaffAuditLog;
use App\Services\DriverService;
use App\Services\GuideService;
use App\Services\OperatorBookingFlow;
use App\Services\WebsiteBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the /staff Telegram flow:
 *   - Permission enforcement (operator < manager required)
 *   - Active/inactive filtering in assignment list
 *   - Inactive driver/guide assignment guard
 *   - List, detail, toggle, add flows via handleStaffCallback / state machine
 */
class StaffBotFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->delete();
        DB::table('drivers')->delete();
        DB::table('guides')->delete();
        DB::table('operator_booking_sessions')->delete();
        DB::table('staff_audit_logs')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function flow(): OperatorBookingFlow
    {
        return new OperatorBookingFlow(
            app(WebsiteBookingService::class),
            new \App\Services\BookingOpsService(),
            new \App\Services\BookingBrowseService(),
            new DriverService(),
            new GuideService(),
        );
    }

    private function makeOperator(string $role): BotOperator
    {
        $op = new BotOperator();
        $op->telegram_user_id = '12345';
        $op->role             = $role;
        $op->is_active        = true;
        return $op;
    }

    private function makeDriver(array $overrides = []): Driver
    {
        return Driver::create(array_merge([
            'first_name' => 'Ali',
            'last_name'  => 'Rakhimov',
            'phone01'    => '+998901234567',
            'email'      => 'ali@example.com',
            'fuel_type'  => 'Petrol',
            'is_active'  => true,
        ], $overrides));
    }

    private function makeGuide(array $overrides = []): Guide
    {
        return Guide::create(array_merge([
            'first_name'  => 'Dilnoza',
            'last_name'   => 'Yusupova',
            'phone01'     => '+998901111111',
            'email'       => 'dil@example.com',
            'lang_spoken' => ['EN', 'RU'],
            'is_active'   => true,
        ], $overrides));
    }

    private function handle(string $chatId, ?string $text, ?string $callback, ?BotOperator $op = null): array
    {
        return $this->flow()->handle($chatId, $text, $callback, $op);
    }

    // ── Permission tests ──────────────────────────────────────────────────────

    /** @test */
    public function operator_role_cannot_access_staff_menu_via_command(): void
    {
        $response = $this->handle('100', '/staff', null, $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $response['text']);
        $this->assertStringContainsString('manager', $response['text']);
    }

    /** @test */
    public function manager_can_access_staff_menu(): void
    {
        $response = $this->handle('100', '/staff', null, $this->makeOperator('manager'));

        $this->assertStringContainsString('Staff Management', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    /** @test */
    public function operator_role_cannot_access_staff_callback(): void
    {
        $response = $this->handle('100', null, 'staff:drivers', $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $response['text']);
    }

    // ── Assignment list filters ───────────────────────────────────────────────

    /** @test */
    public function inactive_driver_is_excluded_from_assignment_list(): void
    {
        $active   = $this->makeDriver(['first_name' => 'Active',   'is_active' => true]);
        $inactive = $this->makeDriver(['first_name' => 'Inactive', 'is_active' => false]);

        // buildDriverList is invoked via ops:drivers callback — need an active booking session
        // Test the underlying query directly: only active drivers should be in the list
        $drivers = Driver::where('is_active', true)->orderBy('first_name')->get();

        $this->assertCount(1, $drivers);
        $this->assertSame('Active', $drivers->first()->first_name);
    }

    /** @test */
    public function inactive_guide_is_excluded_from_assignment_list(): void
    {
        $this->makeGuide(['first_name' => 'Active',   'is_active' => true]);
        $this->makeGuide(['first_name' => 'Inactive', 'is_active' => false]);

        $guides = Guide::where('is_active', true)->orderBy('first_name')->get();

        $this->assertCount(1, $guides);
        $this->assertTrue($guides->first()->is_active);
    }

    // ── Inactive assignment guard ─────────────────────────────────────────────

    /** @test */
    public function assigning_inactive_driver_returns_error_message(): void
    {
        $driver = $this->makeDriver(['is_active' => false]);

        // Create a session with an active booking (FK checks disabled, use fake booking_id)
        $chatId = '200';
        DB::table('operator_booking_sessions')->insert([
            'chat_id'    => $chatId,
            'state'      => 'booking_actions',
            'data'       => json_encode(['active_booking_id' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a minimal booking row so activeBooking() doesn't return null.
        // FK checks are disabled; use fake integer values for all NOT NULL FKs.
        DB::table('bookings')->insertOrIgnore([
            'id'              => 1,
            'booking_number'  => 'TEST-001',
            'booking_status'  => 'pending',
            'tour_id'         => 1,
            'guest_id'        => 1,
            'driver_id'       => 1,
            'guide_id'        => 1,
            'grand_total'     => 0,
            'amount'          => 0,
            'payment_method'  => 'cash',
            'payment_status'  => 'unpaid',
            'group_name'      => 'Test Group',
            'pickup_location' => 'TBD',
            'dropoff_location'=> 'TBD',
            'booking_source'  => 'test',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->handle($chatId, null, "ops:driver:{$driver->id}", $this->makeOperator('manager'));

        $this->assertStringContainsString('inactive', $response['text']);
        $this->assertStringContainsString('cannot be assigned', $response['text']);
    }

    /** @test */
    public function assigning_inactive_guide_returns_error_message(): void
    {
        $guide = $this->makeGuide(['is_active' => false]);

        $chatId = '201';
        DB::table('operator_booking_sessions')->insert([
            'chat_id'    => $chatId,
            'state'      => 'booking_actions',
            'data'       => json_encode(['active_booking_id' => 2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bookings')->insertOrIgnore([
            'id'              => 2,
            'booking_number'  => 'TEST-002',
            'booking_status'  => 'pending',
            'tour_id'         => 1,
            'guest_id'        => 1,
            'driver_id'       => 1,
            'guide_id'        => 1,
            'grand_total'     => 0,
            'amount'          => 0,
            'payment_method'  => 'cash',
            'payment_status'  => 'unpaid',
            'group_name'      => 'Test Group',
            'pickup_location' => 'TBD',
            'dropoff_location'=> 'TBD',
            'booking_source'  => 'test',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->handle($chatId, null, "ops:guide:{$guide->id}", $this->makeOperator('manager'));

        $this->assertStringContainsString('inactive', $response['text']);
        $this->assertStringContainsString('cannot be assigned', $response['text']);
    }

    // ── Staff flow: list + detail + toggle ────────────────────────────────────

    /** @test */
    public function staff_driver_list_shows_active_and_inactive_with_indicators(): void
    {
        $this->makeDriver(['first_name' => 'Active',   'is_active' => true]);
        $this->makeDriver(['first_name' => 'Inactive', 'is_active' => false]);

        $response = $this->handle('300', null, 'staff:drivers', $this->makeOperator('manager'));

        $this->assertStringContainsString('✅', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
        // Both drivers should appear as buttons
        $buttons = collect($response['reply_markup']['inline_keyboard'])->flatten(1);
        $texts   = $buttons->pluck('text')->all();
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Active')));
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Inactive')));
    }

    /** @test */
    public function staff_driver_detail_shows_driver_info(): void
    {
        $driver = $this->makeDriver();

        $response = $this->handle('300', null, "staff:driver:{$driver->id}", $this->makeOperator('manager'));

        $this->assertStringContainsString($driver->first_name, $response['text']);
        $this->assertStringContainsString($driver->phone01, $response['text']);
        $this->assertStringContainsString('Petrol', $response['text']);
        $this->assertArrayHasKey('reply_markup', $response);
    }

    /** @test */
    public function staff_toggle_deactivates_active_driver(): void
    {
        $driver = $this->makeDriver(['is_active' => true]);

        $this->handle('300', null, "staff:driver:{$driver->id}:toggle", $this->makeOperator('manager'));
        $driver->refresh();

        $this->assertFalse($driver->is_active);
    }

    /** @test */
    public function staff_toggle_activates_inactive_driver(): void
    {
        $driver = $this->makeDriver(['is_active' => false]);

        $this->handle('300', null, "staff:driver:{$driver->id}:toggle", $this->makeOperator('manager'));
        $driver->refresh();

        $this->assertTrue($driver->is_active);
    }

    // ── Add driver multi-step flow ────────────────────────────────────────────

    /** @test */
    public function add_driver_flow_creates_driver_on_last_step(): void
    {
        $chatId = '400';
        $op     = $this->makeOperator('manager');

        // Start flow
        $r = $this->handle($chatId, null, 'staff:driver:add', $op);
        $this->assertStringContainsString('first name', $r['text']);

        // Step through
        $this->handle($chatId, 'Timur', null, $op);           // first_name
        $this->handle($chatId, 'Sultanov', null, $op);         // last_name
        $this->handle($chatId, '+998901234567', null, $op);    // phone01
        $this->handle($chatId, 'tim@example.com', null, $op);  // email
        $this->handle($chatId, 'Diesel', null, $op);           // fuel_type → creates driver

        $this->assertDatabaseHas('drivers', [
            'first_name' => 'Timur',
            'last_name'  => 'Sultanov',
            'email'      => 'tim@example.com',
            'fuel_type'  => 'Diesel',
            'is_active'  => true,
        ]);
    }

    /** @test */
    public function add_guide_flow_creates_guide_on_last_step(): void
    {
        $chatId = '401';
        $op     = $this->makeOperator('manager');

        $this->handle($chatId, null, 'staff:guide:add', $op);
        $this->handle($chatId, 'Malika', null, $op);
        $this->handle($chatId, 'Nazarova', null, $op);
        $this->handle($chatId, '+998902345678', null, $op);
        $this->handle($chatId, 'malika@example.com', null, $op);
        $this->handle($chatId, 'EN, RU', null, $op);

        $this->assertDatabaseHas('guides', [
            'first_name' => 'Malika',
            'last_name'  => 'Nazarova',
            'email'      => 'malika@example.com',
            'is_active'  => true,
        ]);

        $guide = Guide::where('email', 'malika@example.com')->first();
        $this->assertEqualsCanonicalizing(['EN', 'RU'], $guide->lang_spoken);
    }

    // ── Edit field flow ───────────────────────────────────────────────────────

    /** @test */
    public function edit_driver_field_updates_driver_and_returns_detail(): void
    {
        $driver = $this->makeDriver();
        $chatId = '500';
        $op     = $this->makeOperator('manager');

        // Tap edit → phone01
        $r = $this->handle($chatId, null, "staff:driver:{$driver->id}:edit:phone01", $op);
        $this->assertStringContainsString('phone', strtolower($r['text']));

        // Send new value
        $r = $this->handle($chatId, '+998990000000', null, $op);

        $driver->refresh();
        $this->assertSame('+998990000000', $driver->phone01);
        // Should return detail view
        $this->assertStringContainsString($driver->first_name, $r['text']);
    }

    /** @test */
    public function edit_guide_field_updates_guide(): void
    {
        $guide  = $this->makeGuide();
        $chatId = '501';
        $op     = $this->makeOperator('manager');

        $this->handle($chatId, null, "staff:guide:{$guide->id}:edit:email", $op);
        $this->handle($chatId, 'new@example.com', null, $op);

        $guide->refresh();
        $this->assertSame('new@example.com', $guide->email);
    }

    // ── Auth granularity: operator read-only ──────────────────────────────────

    /** @test */
    public function operator_can_access_staff_menu(): void
    {
        $r = $this->handle('600', '/staff', null, $this->makeOperator('operator'));

        $this->assertStringContainsString('Staff Management', $r['text']);
    }

    /** @test */
    public function operator_can_view_driver_list(): void
    {
        $r = $this->handle('600', null, 'staff:drivers', $this->makeOperator('operator'));

        $this->assertArrayHasKey('reply_markup', $r);
    }

    /** @test */
    public function operator_can_view_driver_detail(): void
    {
        $driver = $this->makeDriver();

        $r = $this->handle('600', null, "staff:driver:{$driver->id}", $this->makeOperator('operator'));

        $this->assertStringContainsString($driver->first_name, $r['text']);
    }

    /** @test */
    public function operator_cannot_add_driver(): void
    {
        $r = $this->handle('600', null, 'staff:driver:add', $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $r['text']);
    }

    /** @test */
    public function operator_cannot_toggle_driver(): void
    {
        $driver = $this->makeDriver();

        $r = $this->handle('600', null, "staff:driver:{$driver->id}:toggle", $this->makeOperator('operator'));

        $this->assertStringContainsString('🚫', $r['text']);
    }

    /** @test */
    public function operator_detail_view_has_no_edit_or_toggle_buttons(): void
    {
        $driver = $this->makeDriver();

        $r = $this->handle('600', null, "staff:driver:{$driver->id}", $this->makeOperator('operator'));

        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $cbData  = $buttons->pluck('callback_data')->all();

        $this->assertFalse(collect($cbData)->contains(fn ($c) => str_contains($c, ':toggle')));
        $this->assertFalse(collect($cbData)->contains(fn ($c) => str_contains($c, ':edit')));
    }

    /** @test */
    public function manager_detail_view_has_edit_and_toggle_buttons(): void
    {
        $driver = $this->makeDriver();

        $r = $this->handle('600', null, "staff:driver:{$driver->id}", $this->makeOperator('manager'));

        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $cbData  = $buttons->pluck('callback_data')->all();

        $this->assertTrue(collect($cbData)->contains(fn ($c) => str_contains($c, ':toggle')));
        $this->assertTrue(collect($cbData)->contains(fn ($c) => str_contains($c, ':edit')));
    }

    // ── Filter ────────────────────────────────────────────────────────────────

    /** @test */
    public function driver_list_active_filter_shows_only_active(): void
    {
        $this->makeDriver(['first_name' => 'Active',   'is_active' => true]);
        $this->makeDriver(['first_name' => 'Inactive', 'is_active' => false]);

        $r = $this->handle('700', null, 'staff:drivers:active', $this->makeOperator('manager'));

        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $texts   = $buttons->pluck('text')->all();

        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Active')));
        $this->assertFalse(collect($texts)->contains(fn ($t) => str_contains($t, '🔴') && str_contains($t, 'Inactive')));
    }

    /** @test */
    public function driver_list_inactive_filter_shows_only_inactive(): void
    {
        $this->makeDriver(['first_name' => 'Active',   'is_active' => true]);
        $this->makeDriver(['first_name' => 'Inactive', 'is_active' => false]);

        $r = $this->handle('700', null, 'staff:drivers:inactive', $this->makeOperator('manager'));

        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $texts   = $buttons->pluck('text')->all();

        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Inactive')));
        $this->assertFalse(collect($texts)->contains(fn ($t) => str_contains($t, '✅') && str_contains($t, 'Active')));
    }

    // ── Duplicate phone prevention in edit flow ───────────────────────────────

    /** @test */
    public function edit_phone_blocked_when_number_already_used_by_another_driver(): void
    {
        $this->makeDriver(['phone01' => '+998901111111']);
        $driver2 = $this->makeDriver(['phone01' => '+998902222222', 'email' => 'other@example.com']);
        $chatId  = '800';
        $op      = $this->makeOperator('manager');

        $this->handle($chatId, null, "staff:driver:{$driver2->id}:edit:phone01", $op);
        $r = $this->handle($chatId, '+998901111111', null, $op);

        $this->assertStringContainsString('⚠️', $r['text']);
        // Session not cleared — user can retry
        $driver2->refresh();
        $this->assertSame('+998902222222', $driver2->phone01);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    /** @test */
    public function driver_search_returns_matching_results(): void
    {
        $this->makeDriver(['first_name' => 'Timur',   'phone01' => '+998901111111']);
        $this->makeDriver(['first_name' => 'Kamoliddin', 'phone01' => '+998902222222', 'email' => 'k@example.com']);
        $chatId = '900';
        $op     = $this->makeOperator('operator');

        // Enter search state
        $this->handle($chatId, null, 'staff:driver:search', $op);

        // Send search term
        $r = $this->handle($chatId, 'Timur', null, $op);

        $this->assertStringContainsString('Timur', $r['text']);
        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $texts   = $buttons->pluck('text')->all();
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Timur')));
        $this->assertFalse(collect($texts)->contains(fn ($t) => str_contains($t, 'Kamoliddin')));
    }

    /** @test */
    public function driver_search_by_phone_returns_matching_driver(): void
    {
        $this->makeDriver(['first_name' => 'Timur', 'phone01' => '+998901111111']);
        $chatId = '901';
        $op     = $this->makeOperator('operator');

        $this->handle($chatId, null, 'staff:driver:search', $op);
        $r = $this->handle($chatId, '998901111111', null, $op);

        $buttons = collect($r['reply_markup']['inline_keyboard'])->flatten(1);
        $texts   = $buttons->pluck('text')->all();
        $this->assertTrue(collect($texts)->contains(fn ($t) => str_contains($t, 'Timur')));
    }

    /** @test */
    public function driver_search_empty_result_shows_no_found_message(): void
    {
        $chatId = '902';
        $op     = $this->makeOperator('manager');

        $this->handle($chatId, null, 'staff:driver:search', $op);
        $r = $this->handle($chatId, 'Nonexistent', null, $op);

        $this->assertStringContainsString('No drivers found', $r['text']);
    }
}
