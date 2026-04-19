<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadInteraction;
use App\Models\LeadInterest;
use Database\Seeders\LeadCrmSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — schema smoke.
 *
 * Catches FK target missing, column type mismatch, seeder drift in one run.
 */
class LeadCrmMigrationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_tables_exist_and_seeder_populates_them(): void
    {
        $this->assertTrue(Schema::hasTable('leads'));
        $this->assertTrue(Schema::hasTable('lead_interests'));
        $this->assertTrue(Schema::hasTable('lead_interactions'));
        $this->assertTrue(Schema::hasTable('lead_followups'));

        $this->seed(LeadCrmSeeder::class);

        $this->assertGreaterThan(0, Lead::count(), 'seeder should create leads');
        $this->assertGreaterThan(0, LeadInterest::count(), 'seeder should create interests');
        $this->assertGreaterThan(0, LeadInteraction::count(), 'seeder should create interactions');
        $this->assertGreaterThan(0, LeadFollowUp::count(), 'seeder should create followups');
    }
}
