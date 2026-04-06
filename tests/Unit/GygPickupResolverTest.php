<?php

namespace Tests\Unit;

use App\Enums\GygBookingType;
use App\Models\GygInboundEmail;
use App\Services\GygPickupResolver;
use PHPUnit\Framework\TestCase;

class GygPickupResolverTest extends TestCase
{
    private GygPickupResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new GygPickupResolver();
    }

    // ── resolveFromEmail ─────────────────────────────────

    public function test_group_email_resolves_to_meeting_point(): void
    {
        $email = $this->makeEmail(tourType: 'group', tourTypeSource: 'explicit', optionTitle: 'Group Tour – Shahrisabz Day Trip');

        $this->assertSame(GygPickupResolver::GROUP_MEETING_POINT, $this->resolver->resolveFromEmail($email));
    }

    public function test_private_email_resolves_to_null(): void
    {
        $email = $this->makeEmail(tourType: 'private', tourTypeSource: 'defaulted', optionTitle: 'Private Shahrisabz Day Trip – Driver Only');

        $this->assertNull($this->resolver->resolveFromEmail($email));
    }

    public function test_null_tour_type_with_private_option_title_resolves_to_null(): void
    {
        $email = $this->makeEmail(tourType: null, tourTypeSource: null, optionTitle: 'Private Shahrisabz Day Trip – Driver Only');

        $this->assertNull($this->resolver->resolveFromEmail($email));
    }

    // ── classify: Priority 1 — Explicit parser result ────

    public function test_explicit_group_classified_as_group(): void
    {
        $type = $this->resolver->classify('group', 'explicit', 'Some Option');

        $this->assertSame(GygBookingType::Group, $type);
        $this->assertTrue($type->isGroup());
    }

    public function test_explicit_private_classified_as_private(): void
    {
        $type = $this->resolver->classify('private', 'explicit', 'Some Private Option');

        $this->assertSame(GygBookingType::Private, $type);
        $this->assertTrue($type->isPrivate());
    }

    public function test_explicit_source_takes_priority_over_conflicting_option_title(): void
    {
        // tour_type=group(explicit) but option_title says "private" — explicit wins
        $type = $this->resolver->classify('group', 'explicit', 'Private Yurt Camp');

        $this->assertSame(GygBookingType::Group, $type);
    }

    // ── classify: Priority 2 — option_title heuristics ──

    public function test_option_title_group_keyword_classifies_as_group_when_type_defaulted(): void
    {
        $type = $this->resolver->classify('private', 'defaulted', 'Samarkand to Bukhara: 2-Day Group Yurt');

        // option_title has "Group" → overrides defaulted private
        $this->assertSame(GygBookingType::Group, $type);
    }

    public function test_option_title_private_keyword_classifies_as_private_when_no_explicit(): void
    {
        $type = $this->resolver->classify(null, null, 'Private Shahrisabz Day Trip – Driver Only');

        $this->assertSame(GygBookingType::Private, $type);
        $this->assertTrue($type->isPrivate());
    }

    public function test_option_title_group_keyword_overrides_null_tour_type(): void
    {
        $type = $this->resolver->classify(null, null, 'Group Tour with Guide – Shahrisabz Day Trip');

        $this->assertSame(GygBookingType::Group, $type);
    }

    // ── classify: Priority 3 — tour_name heuristics ─────

    public function test_tour_name_private_keyword_used_when_option_title_has_no_keyword(): void
    {
        $type = $this->resolver->classify(null, null, 'Neutral Option Title', 'Private Yurt Camp Tour');

        $this->assertSame(GygBookingType::Private, $type);
    }

    public function test_tour_name_group_keyword_used_when_option_title_has_no_keyword(): void
    {
        $type = $this->resolver->classify(null, null, 'Neutral Option Title', 'Group Day Trip');

        $this->assertSame(GygBookingType::Group, $type);
    }

    public function test_option_title_keyword_takes_priority_over_tour_name(): void
    {
        // option_title says "group" but tour_name says "private" — option_title wins (priority 2 > 3)
        $type = $this->resolver->classify(null, null, 'Group Yurt Tour', 'Private Tour Name');

        $this->assertSame(GygBookingType::Group, $type);
    }

    // ── classify: Priority 4 — Defaulted parser result ──

    public function test_defaulted_private_used_when_no_keyword_in_titles(): void
    {
        $type = $this->resolver->classify('private', 'defaulted', 'Samarkand Day Trip', 'Samarkand Tour');

        // No keyword in titles, fallback to defaulted 'private'
        $this->assertSame(GygBookingType::Private, $type);
    }

    // ── classify: Priority 5 — Conservative fallback ────

    public function test_fully_unknown_input_defaults_to_private(): void
    {
        $type = $this->resolver->classify(null, null, null);

        // Conservative fallback: Private (never wrong meeting point)
        $this->assertSame(GygBookingType::Private, $type);
        $this->assertTrue($type->isPrivate());
    }

    public function test_empty_strings_treated_as_null(): void
    {
        $type = $this->resolver->classify('', '', '', '');

        // Empty values → no signals → conservative fallback
        $this->assertTrue($type->isPrivate());
    }

    // ── GygBookingType enum ──────────────────────────────

    public function test_unknown_type_is_private_for_pickup_purposes(): void
    {
        // Unknown must be treated as Private so we never send wrong meeting point
        $this->assertTrue(GygBookingType::Unknown->isPrivate());
        $this->assertFalse(GygBookingType::Unknown->isGroup());
    }

    public function test_group_meeting_point_constant_is_set(): void
    {
        $this->assertNotEmpty(GygPickupResolver::GROUP_MEETING_POINT);
        $this->assertSame('Gur Emir Mausoleum', GygPickupResolver::GROUP_MEETING_POINT);
    }

    // ── Real-world option titles from production data ────

    /**
     * @dataProvider productionOptionTitleProvider
     */
    public function test_production_option_titles_classify_correctly(
        string $optionTitle,
        ?string $tourType,
        ?string $tourTypeSource,
        GygBookingType $expected,
    ): void {
        $result = $this->resolver->classify($tourType, $tourTypeSource, $optionTitle);
        $this->assertSame($expected, $result, "Option: '{$optionTitle}' should be {$expected->value}");
    }

    public static function productionOptionTitleProvider(): array
    {
        return [
            'group yurt bukhara explicit'  => ['Samarkand to Bukhara: 2-Day Group Yurt & Camel', 'group', 'explicit', GygBookingType::Group],
            'group shahrisabz guide'       => ['Group Tour with Guide – Shahrisabz Day Trip', 'group', 'explicit', GygBookingType::Group],
            'private shahrisabz driver'    => ['Private Shahrisabz Day Trip – Driver Only', null, null, GygBookingType::Private],
            'private yurt journey'         => ['Private 2-Day Desert Yurt Camp Journey', 'private', 'defaulted', GygBookingType::Private],
            'private yurt journey explicit'=> ['Private 2-Day Desert Yurt Camp Journey', 'private', 'explicit', GygBookingType::Private],
        ];
    }

    // ── Reminder message wording integration ────────────

    /**
     * Reminder sends "Gur Emir Mausoleum" for group tours.
     */
    public function test_group_pickup_used_verbatim_in_reminder(): void
    {
        $email   = $this->makeEmail(tourType: 'group', tourTypeSource: 'explicit', optionTitle: 'Group Tour – Shahrisabz');
        $pickup  = $this->resolver->resolveFromEmail($email);
        $message = $this->buildReminderMessage($pickup);

        $this->assertStringContainsString('Gur Emir Mausoleum', $message);
        $this->assertStringNotContainsString('your hotel', $message);
    }

    /**
     * Reminder falls back to "your hotel" when pickup_location is null (private tours).
     */
    public function test_private_pickup_null_causes_reminder_to_say_your_hotel(): void
    {
        $email  = $this->makeEmail(tourType: 'private', tourTypeSource: 'defaulted', optionTitle: 'Private Shahrisabz Trip');
        $pickup = $this->resolver->resolveFromEmail($email);

        // Simulate reminder fallback: $pickup = $pickupLocation ?: 'your hotel'
        $message = $this->buildReminderMessage($pickup);

        $this->assertStringContainsString('your hotel', $message);
        $this->assertStringNotContainsString('Gur Emir Mausoleum', $message);
    }

    // ── Helpers ──────────────────────────────────────────

    private function makeEmail(
        ?string $tourType,
        ?string $tourTypeSource,
        ?string $optionTitle,
        ?string $tourName = null,
    ): GygInboundEmail {
        $email                  = new GygInboundEmail();
        $email->tour_type       = $tourType;
        $email->tour_type_source = $tourTypeSource;
        $email->option_title    = $optionTitle;
        $email->tour_name       = $tourName;
        return $email;
    }

    /** Mirrors the buildGuestMessage pickup logic in TourSendReminders. */
    private function buildReminderMessage(?string $pickupLocation): string
    {
        $pickup = $pickupLocation ?: 'your hotel';
        return "📍 Pickup: {$pickup}";
    }
}
