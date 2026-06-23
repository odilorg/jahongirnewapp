<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WaLeadDecision;
use Tests\TestCase;

/** Pure decision rules (app booted for config, no DB). */
class WaLeadDecisionTest extends TestCase
{
    private WaLeadDecision $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->d = new WaLeadDecision();
    }

    public function test_genuine_at_threshold_auto_creates(): void
    {
        $this->assertSame(WaLeadDecision::AUTO_CREATE, $this->d->decide('genuine_tour_inquiry', 0.85, null));
        $this->assertSame(WaLeadDecision::REVIEW, $this->d->decide('genuine_tour_inquiry', 0.84, null));
    }

    public function test_only_junk_subtypes_auto_dismiss(): void
    {
        foreach (['spam', 'b2b', 'supplier'] as $junk) {
            $this->assertSame(WaLeadDecision::AUTO_DISMISS, $this->d->decide('not_lead', 0.95, $junk), $junk);
        }
        foreach (['accommodation', 'logistics', 'personal', 'other', null] as $keep) {
            $this->assertSame(WaLeadDecision::REVIEW, $this->d->decide('not_lead', 0.95, $keep),
                'subtype ' . var_export($keep, true) . ' must NOT auto-dismiss');
        }
    }

    public function test_accommodation_is_never_dismissed_even_at_high_confidence(): void
    {
        $this->assertSame(WaLeadDecision::REVIEW, $this->d->decide('not_lead', 0.99, 'accommodation'));
    }

    public function test_not_lead_below_threshold_reviews(): void
    {
        $this->assertSame(WaLeadDecision::REVIEW, $this->d->decide('not_lead', 0.89, 'spam'));
    }

    public function test_uncertain_reviews(): void
    {
        $this->assertSame(WaLeadDecision::REVIEW, $this->d->decide('uncertain', 0.99, null));
    }
}
