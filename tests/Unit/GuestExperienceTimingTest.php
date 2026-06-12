<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\GuestExperienceMessageType;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The due-time computation is the riskiest logic in the engine — these
 * tests lock the offset rules per message type, including the day-2
 * derivation for the next-morning feedback prompt.
 */
class GuestExperienceTimingTest extends TestCase
{
    private function departure(string $datetime): Carbon
    {
        return Carbon::parse($datetime, 'Asia/Tashkent');
    }

    /** @test */
    public function welcome_is_one_hour_after_pickup(): void
    {
        $dep = $this->departure('2026-07-01 09:00');
        $due = GuestExperienceMessageType::PostPickupWelcome->dueAt($dep);

        $this->assertSame('2026-07-01 10:00', $due->format('Y-m-d H:i'));
        $this->assertSame('+05:00', $due->format('P'));
    }

    /** @test */
    public function welcome_tracks_a_non_default_pickup_time(): void
    {
        $dep = $this->departure('2026-07-01 07:30');
        $due = GuestExperienceMessageType::PostPickupWelcome->dueAt($dep);

        $this->assertSame('2026-07-01 08:30', $due->format('Y-m-d H:i'));
    }

    /** @test */
    public function sunset_tip_fires_a_fixed_lead_before_real_sunset(): void
    {
        config(['guest_experience.sunset' => [
            'lat' => 40.70, 'lng' => 65.60, 'minutes_before' => 40, 'fallback_time' => '18:30',
        ]]);

        $departure = $this->departure('2026-07-01 07:30');
        $due = GuestExperienceMessageType::EveningSunsetTip->dueAt($departure);

        // Independently compute the expected: real sunset for these coords on
        // day 1, minus 40 min, in Tashkent.
        $noon = $departure->copy()->startOfDay()->setTime(12, 0);
        $info = date_sun_info($noon->getTimestamp(), 40.70, 65.60);
        $expected = Carbon::createFromTimestampUTC($info['sunset'])
            ->setTimezone('Asia/Tashkent')
            ->subMinutes(40);

        $this->assertSame($expected->format('Y-m-d H:i'), $due->format('Y-m-d H:i'));
        // Sanity: a July Aydarkul sunset-minus-40 lands in the evening.
        $this->assertSame('2026-07-01', $due->format('Y-m-d'));
        $this->assertGreaterThanOrEqual(18, (int) $due->format('H'));
    }

    /** @test */
    public function sunset_tip_shifts_earlier_in_winter_than_summer(): void
    {
        config(['guest_experience.sunset' => [
            'lat' => 40.70, 'lng' => 65.60, 'minutes_before' => 40, 'fallback_time' => '18:30',
        ]]);

        $summer = GuestExperienceMessageType::EveningSunsetTip->dueAt($this->departure('2026-06-21 09:00'));
        $winter = GuestExperienceMessageType::EveningSunsetTip->dueAt($this->departure('2026-12-21 09:00'));

        // Winter sunset is materially earlier than summer — proves it tracks
        // the date rather than a fixed clock time.
        $this->assertTrue(
            (int) $winter->format('Hi') < (int) $summer->format('Hi') - 100,
            "winter ({$winter->format('H:i')}) should be well before summer ({$summer->format('H:i')})",
        );
    }

    /** @test */
    public function feedback_is_day_two_at_0830(): void
    {
        $due = GuestExperienceMessageType::NextMorningFeedback->dueAt($this->departure('2026-07-01 09:00'));

        $this->assertSame('2026-07-02 08:30', $due->format('Y-m-d H:i'));
    }

    /** @test */
    public function feedback_requires_a_two_day_tour(): void
    {
        $this->assertSame(1, GuestExperienceMessageType::PostPickupWelcome->requiresDayCount());
        $this->assertSame(1, GuestExperienceMessageType::EveningSunsetTip->requiresDayCount());
        $this->assertSame(2, GuestExperienceMessageType::NextMorningFeedback->requiresDayCount());
    }
}
