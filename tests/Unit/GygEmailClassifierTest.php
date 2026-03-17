<?php

namespace Tests\Unit;

use App\Services\GygEmailClassifier;
use PHPUnit\Framework\TestCase;

class GygEmailClassifierTest extends TestCase
{
    private GygEmailClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new GygEmailClassifier();
    }

    // ── Classification ──────────────────────────────────

    public function test_classifies_new_booking(): void
    {
        $result = $this->classifier->classify(
            'Booking - S374926 - GYGZGZ5XLFNQ',
            'do-not-reply@notification.getyourguide.com'
        );
        $this->assertEquals('new_booking', $result);
    }

    public function test_classifies_cancellation(): void
    {
        $result = $this->classifier->classify(
            'A booking has been canceled - S374926 - GYGWZBBA7MMR',
            'do-not-reply@notification.getyourguide.com'
        );
        $this->assertEquals('cancellation', $result);
    }

    public function test_classifies_amendment(): void
    {
        $result = $this->classifier->classify(
            'Booking detail change: - S374926 - GYG6H8GK23WV',
            'do-not-reply@notification.getyourguide.com'
        );
        $this->assertEquals('amendment', $result);
    }

    public function test_classifies_guest_reply(): void
    {
        $result = $this->classifier->classify(
            'Re: Your Yurt Camp Tour — Dietary Requirements',
            'customer-abc@reply.getyourguide.com'
        );
        $this->assertEquals('guest_reply', $result);
    }

    public function test_classifies_unknown_gyg_email(): void
    {
        $result = $this->classifier->classify(
            'Welcome to GetYourGuide!',
            'info@getyourguide.com'
        );
        $this->assertEquals('unknown', $result);
    }

    public function test_re_prefix_from_non_reply_domain_is_not_guest_reply(): void
    {
        $result = $this->classifier->classify(
            'Re: Something',
            'do-not-reply@notification.getyourguide.com'
        );
        $this->assertEquals('unknown', $result);
    }

    // ── Reference extraction ────────────────────────────

    public function test_extracts_reference_from_booking_subject(): void
    {
        $ref = $this->classifier->extractReferenceFromSubject('Booking - S374926 - GYGZGZ5XLFNQ');
        $this->assertEquals('GYGZGZ5XLFNQ', $ref);
    }

    public function test_extracts_reference_from_cancellation_subject(): void
    {
        $ref = $this->classifier->extractReferenceFromSubject('A booking has been canceled - S374926 - GYGWZBBA7MMR');
        $this->assertEquals('GYGWZBBA7MMR', $ref);
    }

    public function test_returns_null_for_subject_without_reference(): void
    {
        $ref = $this->classifier->extractReferenceFromSubject('Re: Your Tour');
        $this->assertNull($ref);
    }
}
