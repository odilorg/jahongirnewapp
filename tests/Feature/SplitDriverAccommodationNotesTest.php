<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Calendar\Save\QuickSaveOperationalNotesAction;
use App\Models\Accommodation;
use App\Models\BookingInquiry;
use App\Models\InquiryStay;
use App\Services\DriverDispatchNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guest-context notes split into two recipient-specific fields.
 *
 * - operational_notes → driver/guide
 * - accommodation_notes → camp/hotel
 *
 * The four calendar flags (♿🍃🗣🎉) must derive from the UNION of both,
 * and the camp dispatch must now carry accommodation needs while the
 * driver dispatch must not.
 */
class SplitDriverAccommodationNotesTest extends TestCase
{
    use DatabaseTransactions;

    private function inquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'website',
            'status' => BookingInquiry::STATUS_CONFIRMED,
            'customer_name' => 'Test Guest',
            'customer_phone' => '+998901234567',
            'tour_name_snapshot' => 'Yurt Camp',
            'people_adults' => 2,
            'people_children' => 0,
            'travel_date' => Carbon::now('Asia/Samarkand')->addDay()->toDateString(),
            'pickup_time' => '09:00:00',
            'submitted_at' => now(),
        ], $overrides));
    }

    private function save(BookingInquiry $inq, string $field, ?string $notes): \App\Actions\Calendar\Support\CalendarActionResult
    {
        return app(QuickSaveOperationalNotesAction::class)->handle($inq, [
            'field' => $field,
            'notes' => $notes,
            'operator_name' => 'tester',
        ]);
    }

    /** @test */
    public function each_field_saves_to_its_own_column(): void
    {
        $inq = $this->inquiry();

        $this->save($inq, QuickSaveOperationalNotesAction::FIELD_DRIVER, 'wheelchair user, French speaker');
        $this->save($inq->fresh(), QuickSaveOperationalNotesAction::FIELD_ACCOMMODATION, 'vegetarian, honeymoon');

        $inq->refresh();
        $this->assertSame('wheelchair user, French speaker', $inq->operational_notes);
        $this->assertSame('vegetarian, honeymoon', $inq->accommodation_notes);
    }

    /** @test */
    public function flags_derive_from_the_union_of_both_fields(): void
    {
        $inq = $this->inquiry();

        // Driver field carries accessibility + language; accommodation carries
        // dietary + occasion. All four flags must end up true.
        $this->save($inq, QuickSaveOperationalNotesAction::FIELD_DRIVER, 'wheelchair user, French speaker');
        $this->save($inq->fresh(), QuickSaveOperationalNotesAction::FIELD_ACCOMMODATION, 'vegetarian, honeymoon');

        $inq->refresh();
        $this->assertTrue($inq->has_accessibility_flag);
        $this->assertTrue($inq->has_language_flag);
        $this->assertTrue($inq->has_dietary_flag, 'dietary must survive — set from the accommodation field');
        $this->assertTrue($inq->has_occasion_flag);
    }

    /** @test */
    public function saving_one_field_does_not_wipe_the_other_fields_flag(): void
    {
        $inq = $this->inquiry();

        // Set a dietary need via accommodation, then save an unrelated driver note.
        $this->save($inq, QuickSaveOperationalNotesAction::FIELD_ACCOMMODATION, 'severe nut allergy');
        $this->assertTrue($inq->fresh()->has_dietary_flag);

        $this->save($inq->fresh(), QuickSaveOperationalNotesAction::FIELD_DRIVER, 'meet at lobby');

        // Dietary flag must persist — it comes from the still-present accommodation note.
        $this->assertTrue($inq->fresh()->has_dietary_flag, 'driver save must not wipe the dietary flag');
    }

    /** @test */
    public function per_field_length_cap_is_enforced(): void
    {
        $inq = $this->inquiry();
        $result = $this->save($inq, QuickSaveOperationalNotesAction::FIELD_ACCOMMODATION, str_repeat('x', 301));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Accommodation', $result->message);
    }

    /** @test */
    public function camp_dispatch_includes_accommodation_notes_and_driver_dispatch_does_not(): void
    {
        $inq = $this->inquiry([
            'operational_notes' => 'Wheelchair user, French speaker',
            'accommodation_notes' => 'Vegetarian, honeymoon',
            'pickup_point' => 'Swissotel',
        ]);
        $acc = Accommodation::create(['name' => 'Aydarkul Yurt Camp', 'is_active' => true]);
        $stay = InquiryStay::create([
            'booking_inquiry_id' => $inq->id,
            'accommodation_id' => $acc->id,
            'stay_date' => $inq->travel_date,
            'nights' => 1,
            'guest_count' => 2,
            'notes' => 'late check-in',
        ]);

        $notifier = app(DriverDispatchNotifier::class);

        $stayMsg = $this->callPrivate($notifier, 'buildStayMessage', [$inq, $stay]);
        $this->assertStringContainsString('Vegetarian, honeymoon', $stayMsg, 'camp must receive accommodation needs');
        $this->assertStringContainsString('late check-in', $stayMsg, 'per-stay notes still present');

        $driverMsg = $this->callPrivate($notifier, 'buildMessage', [$inq, 'driver_dispatch_uz']);
        $this->assertStringContainsString('Wheelchair user', $driverMsg);
        $this->assertStringNotContainsString('Vegetarian, honeymoon', $driverMsg, 'driver must NOT get accommodation needs');
    }

    /** @test */
    public function empty_accommodation_notes_omit_the_camp_line_cleanly(): void
    {
        $inq = $this->inquiry(['accommodation_notes' => null]);
        $acc = Accommodation::create(['name' => 'Aydarkul Yurt Camp', 'is_active' => true]);
        $stay = InquiryStay::create([
            'booking_inquiry_id' => $inq->id,
            'accommodation_id' => $acc->id,
            'stay_date' => $inq->travel_date,
            'nights' => 1,
            'guest_count' => 2,
        ]);

        $msg = $this->callPrivate(app(DriverDispatchNotifier::class), 'buildStayMessage', [$inq, $stay]);

        $this->assertStringNotContainsString('Пожелания гостя', $msg, 'no empty guest-needs label');
        $this->assertStringNotContainsString("\n\n\n", $msg, 'no triple blank line');
    }

    private function callPrivate(object $obj, string $method, array $args): string
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return (string) $m->invoke($obj, ...$args);
    }
}
