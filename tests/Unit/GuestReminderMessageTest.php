<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\TourSendReminders;
use Tests\TestCase;
use ReflectionMethod;

/**
 * Tests for the guest WhatsApp day-before reminder message.
 * Catches: wrong field names, missing lines, stale placeholders.
 */
class GuestReminderMessageTest extends TestCase
{
    private function buildMessage(
        string $firstName = 'Christine',
        string $tourTitle = 'Yurt Camp Tour',
        string $dateLabel = 'Fri, 18 Apr 2026',
        ?string $pickup = 'Registan Plaza Hotel',
        string $pickupTime = '09:00',
        ?string $driverName = 'Muhammad',
        ?string $driverPhone = '+998901234567',
        ?string $guideName = null,
        ?string $guidePhone = null,
    ): string {
        $cmd = new TourSendReminders(
            new \App\Services\Messaging\WhatsAppSender(),
        );

        $method = new ReflectionMethod($cmd, 'buildGuestMessage');

        return $method->invoke(
            $cmd, $firstName, $tourTitle, $dateLabel,
            $pickup, $pickupTime, $driverName, $driverPhone,
            $guideName, $guidePhone,
        );
    }

    public function test_message_includes_guest_name(): void
    {
        $msg = $this->buildMessage(firstName: 'Anna');
        $this->assertStringContainsString('Hi Anna!', $msg);
    }

    public function test_message_includes_tour_title(): void
    {
        $msg = $this->buildMessage(tourTitle: 'Shahrisabz Day Trip');
        $this->assertStringContainsString('Shahrisabz Day Trip', $msg);
    }

    public function test_message_includes_date_and_pickup_time(): void
    {
        $msg = $this->buildMessage(dateLabel: 'Sat, 19 Apr 2026', pickupTime: '08:30');
        $this->assertStringContainsString('Sat, 19 Apr 2026', $msg);
        $this->assertStringContainsString('08:30', $msg);
    }

    public function test_message_includes_pickup_location_when_filled(): void
    {
        $msg = $this->buildMessage(pickup: 'Hilton Garden Inn');
        $this->assertStringContainsString('Hilton Garden Inn', $msg);
    }

    public function test_message_omits_pickup_line_when_null(): void
    {
        $msg = $this->buildMessage(pickup: null);
        $this->assertStringNotContainsString('Location:', $msg);
    }

    public function test_message_includes_driver_name_and_phone(): void
    {
        $msg = $this->buildMessage(driverName: 'Avaz', driverPhone: '+998937771234');
        $this->assertStringContainsString('Driver: Avaz (+998937771234)', $msg);
    }

    public function test_message_includes_driver_name_without_phone(): void
    {
        $msg = $this->buildMessage(driverName: 'Avaz', driverPhone: null);
        $this->assertStringContainsString('Driver: Avaz', $msg);
        $this->assertStringNotContainsString('()', $msg);
    }

    public function test_message_omits_driver_line_when_no_driver(): void
    {
        $msg = $this->buildMessage(driverName: null, driverPhone: null);
        $this->assertStringNotContainsString('Driver:', $msg);
    }

    public function test_message_includes_guide_when_assigned(): void
    {
        $msg = $this->buildMessage(guideName: 'Mehroj', guidePhone: '+998507774207');
        $this->assertStringContainsString('Guide: Mehroj (+998507774207)', $msg);
    }

    public function test_message_omits_guide_line_when_no_guide(): void
    {
        $msg = $this->buildMessage(guideName: null, guidePhone: null);
        $this->assertStringNotContainsString('Guide:', $msg);
    }

    public function test_message_includes_both_driver_and_guide(): void
    {
        $msg = $this->buildMessage(
            driverName: 'Muhammad', driverPhone: '+998901234567',
            guideName: 'Mehroj', guidePhone: '+998507774207',
        );
        $this->assertStringContainsString('Driver: Muhammad', $msg);
        $this->assertStringContainsString('Guide: Mehroj', $msg);
    }

    public function test_message_ends_with_signature(): void
    {
        $msg = $this->buildMessage();
        $this->assertStringContainsString('Jahongir Travel', $msg);
    }

    public function test_message_contains_no_raw_placeholders(): void
    {
        $msg = $this->buildMessage();
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $msg);
    }
}
