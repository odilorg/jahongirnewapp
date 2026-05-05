<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Filament\Resources\BookingInquiryResource\Pages\ListBookingInquiries;
use App\Models\BookingInquiry;
use App\Models\TourProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 1.7.1 — Review follow-up tab on BookingInquiryResource.
 *
 * Pins the "tour ended today" filtering contract:
 *   1. Single-day tours (no catalog product) ending today appear
 *   2. Multi-day tours (catalog duration > 1) appear when end-date
 *      = today, computed as travel_date + (duration_days - 1)
 *   3. Cancelled bookings are excluded
 *   4. Already-sent review requests STAY in the list (operator may
 *      want to resend) — only the review_request_sent_at timestamp
 *      flags them as already-sent
 *   5. Bookings ending other days do NOT appear by default
 *   6. Visiting the page sends nothing (it's a navigation surface only)
 */
final class ReviewFollowupTabTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin');
        Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adminUser(): User
    {
        $u = User::factory()->create();
        $u->assignRole('super_admin');
        return $u;
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference'          => BookingInquiry::generateReference(),
            'source'             => 'manual',
            'status'             => BookingInquiry::STATUS_CONFIRMED,
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '+998901234567',
            'tour_name_snapshot' => 'Test Tour',
            'people_adults'      => 2,
            'travel_date'        => '2026-05-05',
            'submitted_at'       => now()->subDays(2),
        ], $overrides));
    }

    private function makeMultiDayProduct(int $days): TourProduct
    {
        return TourProduct::create([
            'title'           => 'Multi-day Test',
            'slug'            => 'multi-' . uniqid(),
            'region'          => 'samarkand',
            'is_active'       => true,
            'duration_days'   => $days,
            'duration_nights' => $days - 1,
        ]);
    }

    /** @test */
    public function single_day_tour_ending_today_appears(): void
    {
        $today  = $this->makeInquiry(['travel_date' => '2026-05-05']);
        $admin  = $this->adminUser();
        $this->actingAs($admin);

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanSeeTableRecords([$today]);
    }

    /** @test */
    public function multi_day_tour_with_end_date_today_appears(): void
    {
        // 3-day tour starting May 3 ends May 5 → travel_date + 2 = today.
        $product = $this->makeMultiDayProduct(3);
        $multi   = $this->makeInquiry([
            'travel_date'     => '2026-05-03',
            'tour_product_id' => $product->id,
        ]);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanSeeTableRecords([$multi]);
    }

    /** @test */
    public function cancelled_booking_is_excluded(): void
    {
        $cancelled = $this->makeInquiry([
            'travel_date'  => '2026-05-05',
            'status'       => BookingInquiry::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanNotSeeTableRecords([$cancelled]);
    }

    /** @test */
    public function already_sent_booking_still_appears(): void
    {
        $sent = $this->makeInquiry([
            'travel_date'             => '2026-05-05',
            'review_request_sent_at'  => now()->subHour(),
        ]);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanSeeTableRecords([$sent]);
    }

    /** @test */
    public function tour_ending_yesterday_does_not_appear(): void
    {
        $yesterday = $this->makeInquiry(['travel_date' => '2026-05-04']);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanNotSeeTableRecords([$yesterday]);
    }

    /** @test */
    public function tour_ending_tomorrow_does_not_appear(): void
    {
        $tomorrow = $this->makeInquiry(['travel_date' => '2026-05-06']);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertCanNotSeeTableRecords([$tomorrow]);
    }

    /** @test */
    public function tab_load_does_not_send_any_message(): void
    {
        // Pin the "navigation only" contract: visiting the page must
        // never trigger a TripAdvisor send. We mock the WhatsApp sender
        // and assert it was never called during the request lifecycle.
        $sender = $this->mock(\App\Services\Messaging\WhatsAppSender::class);
        $sender->shouldNotReceive('send');

        $this->makeInquiry(['travel_date' => '2026-05-05']);
        $this->actingAs($this->adminUser());

        Livewire::test(ListBookingInquiries::class, ['activeTab' => 'review_followup'])
            ->assertSuccessful();
    }
}
