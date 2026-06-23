<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Services\WhatsApp\WaLeadPrefilter;
use Tests\TestCase;

class WaLeadPrefilterTest extends TestCase
{
    private WaLeadPrefilter $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new WaLeadPrefilter();
    }

    public function test_saved_contact_excluded_as_supplier(): void
    {
        $this->assertSame('supplier', $this->p->excludeReason('anything', true));
    }

    public function test_b2b_message_excluded(): void
    {
        $this->assertSame('b2b', $this->p->excludeReason("Hi, I'm founder of GuideMeet, a B2B platform", false));
        $this->assertSame('b2b', $this->p->excludeReason('We offer digital marketing for your business', false));
    }

    public function test_genuine_tour_message_passes_to_ai(): void
    {
        $this->assertNull($this->p->excludeReason('price for 2 pax yurt camp tomorrow?', false));
        $this->assertNull($this->p->excludeReason('Do you have group tours from Tashkent?', false));
    }
}
