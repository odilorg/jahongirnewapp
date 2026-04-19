<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\FindOrCreateLeadByContact;
use App\Enums\LeadSource;
use App\Exceptions\Leads\AmbiguousLeadMatchException;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — contact-to-lead resolution.
 *
 * This is the entry point every future inbound channel (WA, TG, email) will
 * go through. Silent misbehaviour here merges strangers' history — so the
 * ambiguity path is the critical one.
 */
class FindOrCreateLeadByContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_existing_lead_matched_by_highest_priority_field(): void
    {
        // Two leads that share an email (low-priority field) but differ by tg_chat_id.
        $target = Lead::factory()->create([
            'telegram_chat_id' => '111222',
            'email'            => 'shared@example.com',
        ]);
        Lead::factory()->create([
            'telegram_chat_id' => '999888',
            'email'            => 'shared@example.com',
        ]);

        // tg_chat_id wins before email is ever checked — no ambiguity.
        $resolved = app(FindOrCreateLeadByContact::class)->handle([
            'telegram_chat_id' => '111222',
            'email'            => 'shared@example.com',
        ]);

        $this->assertSame($target->id, $resolved->id);
    }

    public function test_creates_new_lead_when_nothing_matches(): void
    {
        $this->assertSame(0, Lead::count());

        $lead = app(FindOrCreateLeadByContact::class)->handle([
            'phone' => '+998901000001',
            'email' => 'new@example.com',
        ], [
            'source' => LeadSource::WhatsAppIn->value,
            'name'   => 'Fresh Contact',
        ]);

        $this->assertSame(1, Lead::count());
        $this->assertSame('+998901000001', $lead->phone);
        $this->assertSame('new@example.com', $lead->email);
        $this->assertSame('Fresh Contact', $lead->name);
        $this->assertSame(LeadSource::WhatsAppIn, $lead->source);
    }

    public function test_throws_when_a_single_high_priority_field_matches_multiple_leads(): void
    {
        Lead::factory()->create(['whatsapp_number' => '+998901112233']);
        Lead::factory()->create(['whatsapp_number' => '+998901112233']);

        $this->expectException(AmbiguousLeadMatchException::class);

        app(FindOrCreateLeadByContact::class)->handle([
            'whatsapp_number' => '+998901112233',
        ]);
    }
}
