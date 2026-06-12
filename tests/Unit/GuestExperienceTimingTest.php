<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\GuestExperienceMessageType;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

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
    public function sunset_tip_is_day_one_at_1830_regardless_of_pickup(): void
    {
        $due = GuestExperienceMessageType::EveningSunsetTip->dueAt($this->departure('2026-07-01 07:30'));

        $this->assertSame('2026-07-01 18:30', $due->format('Y-m-d H:i'));
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
