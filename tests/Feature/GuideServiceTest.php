<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guide;
use App\Models\StaffAuditLog;
use App\Services\GuideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuideServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('bookings')->delete();
        DB::table('guides')->delete();
        DB::table('staff_audit_logs')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function service(): GuideService
    {
        return new GuideService();
    }

    private function minimalData(array $overrides = []): array
    {
        return array_merge([
            'first_name'  => 'Aziz',
            'last_name'   => 'Karimov',
            'phone01'     => '+998901111111',
            'email'       => 'aziz@example.com',
            'lang_spoken' => ['EN', 'RU'],
        ], $overrides);
    }

    // ── create ────────────────────────────────────────────────────────────────

    /** @test */
    public function create_persists_guide_and_defaults_to_active(): void
    {
        $guide = $this->service()->create($this->minimalData(), 'actor:1');

        $this->assertInstanceOf(Guide::class, $guide);
        $this->assertDatabaseHas('guides', [
            'first_name' => 'Aziz',
            'last_name'  => 'Karimov',
            'email'      => 'aziz@example.com',
            'is_active'  => true,
        ]);
    }

    /** @test */
    public function create_accepts_comma_string_for_lang_spoken(): void
    {
        $guide = $this->service()->create(
            $this->minimalData(['lang_spoken' => 'EN, ru, uz']),
            'actor:1',
        );

        $this->assertSame(['EN', 'RU', 'UZ'], $guide->lang_spoken);
    }

    /** @test */
    public function create_accepts_array_for_lang_spoken(): void
    {
        $guide = $this->service()->create(
            $this->minimalData(['lang_spoken' => ['EN', 'RU']]),
            'actor:1',
        );

        $this->assertEqualsCanonicalizing(['EN', 'RU'], $guide->lang_spoken);
    }

    // ── update ────────────────────────────────────────────────────────────────

    /** @test */
    public function update_changes_only_provided_fields(): void
    {
        $guide = Guide::create($this->minimalData());

        $this->service()->update($guide, ['first_name' => 'Bobur'], 'actor:1');
        $guide->refresh();

        $this->assertSame('Bobur', $guide->first_name);
        $this->assertSame('Karimov', $guide->last_name);
    }

    /** @test */
    public function update_normalises_lang_spoken(): void
    {
        $guide = Guide::create($this->minimalData());

        $this->service()->update($guide, ['lang_spoken' => 'DE, fr'], 'actor:1');
        $guide->refresh();

        $this->assertEqualsCanonicalizing(['DE', 'FR'], $guide->lang_spoken);
    }

    /** @test */
    public function update_is_no_op_when_lang_spoken_unchanged(): void
    {
        $guide = Guide::create($this->minimalData(['lang_spoken' => ['EN', 'RU']]));

        // Same languages in different order — should not be a change
        $this->service()->update($guide, ['lang_spoken' => 'RU, EN'], 'actor:1');
        $guide->refresh();

        $this->assertEqualsCanonicalizing(['EN', 'RU'], $guide->lang_spoken);
    }

    // ── setActive ─────────────────────────────────────────────────────────────

    /** @test */
    public function set_active_deactivates_a_guide(): void
    {
        $guide = Guide::create($this->minimalData(['is_active' => true]));

        $this->service()->setActive($guide, false, 'actor:1');
        $guide->refresh();

        $this->assertFalse($guide->is_active);
    }

    /** @test */
    public function set_active_activates_an_inactive_guide(): void
    {
        $guide = Guide::create($this->minimalData(['is_active' => false]));

        $this->service()->setActive($guide, true, 'actor:1');
        $guide->refresh();

        $this->assertTrue($guide->is_active);
    }

    // ── list ──────────────────────────────────────────────────────────────────

    /** @test */
    public function list_returns_all_guides_by_default(): void
    {
        Guide::create($this->minimalData(['is_active' => true]));
        Guide::create($this->minimalData(['first_name' => 'Inactive', 'is_active' => false]));

        $this->assertCount(2, $this->service()->list());
    }

    /** @test */
    public function list_only_active_excludes_inactive_guides(): void
    {
        Guide::create($this->minimalData(['is_active' => true]));
        Guide::create($this->minimalData(['first_name' => 'Inactive', 'is_active' => false]));

        $active = $this->service()->list(onlyActive: true);

        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->is_active);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    /** @test */
    public function find_returns_guide_by_id(): void
    {
        $guide = Guide::create($this->minimalData());

        $this->assertSame($guide->id, $this->service()->find($guide->id)->id);
    }

    /** @test */
    public function find_throws_when_guide_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Guide #9999 not found/');

        $this->service()->find(9999);
    }

    // ── audit logging ─────────────────────────────────────────────────────────

    /** @test */
    public function create_writes_audit_log_entry(): void
    {
        $this->service()->create($this->minimalData(), 'telegram:99');

        $log = StaffAuditLog::where('entity_type', 'guide')->where('action', 'created')->first();

        $this->assertNotNull($log);
        $this->assertSame('telegram:99', $log->actor);
        $this->assertArrayHasKey('phone01', $log->changes);
    }

    /** @test */
    public function update_writes_audit_log_entry(): void
    {
        $guide = Guide::create($this->minimalData());

        $this->service()->update($guide, ['first_name' => 'Updated'], 'telegram:99');

        $log = StaffAuditLog::where('entity_type', 'guide')->where('action', 'updated')->first();

        $this->assertNotNull($log);
        $this->assertSame($guide->id, $log->entity_id);
        $this->assertArrayHasKey('first_name', $log->changes);
    }

    /** @test */
    public function set_active_writes_audit_log_entry(): void
    {
        $guide = Guide::create($this->minimalData(['is_active' => true]));

        $this->service()->setActive($guide, false, 'telegram:99');

        $log = StaffAuditLog::where('entity_type', 'guide')->where('action', 'deactivated')->first();

        $this->assertNotNull($log);
        $this->assertSame($guide->id, $log->entity_id);
    }

    // ── duplicate phone prevention ────────────────────────────────────────────

    /** @test */
    public function create_throws_on_duplicate_normalized_phone(): void
    {
        $this->service()->create($this->minimalData(['phone01' => '+998901111111']), 'actor:1');

        $this->expectException(\RuntimeException::class);

        $this->service()->create($this->minimalData(['email' => 'other@example.com', 'phone01' => '998901111111']), 'actor:1');
    }

    /** @test */
    public function update_throws_when_new_phone_matches_another_guide(): void
    {
        Guide::create($this->minimalData(['phone01' => '+998901111111']));
        $guide2 = Guide::create($this->minimalData(['phone01' => '+998902222222', 'email' => 'other@example.com']));

        $this->expectException(\RuntimeException::class);

        $this->service()->update($guide2, ['phone01' => '+998901111111'], 'actor:1');
    }

    /** @test */
    public function update_allows_same_guide_to_keep_own_phone(): void
    {
        $guide = Guide::create($this->minimalData(['phone01' => '+998901111111']));

        $this->service()->update($guide, ['phone01' => '+998901111111'], 'actor:1');

        $this->assertSame('+998901111111', $guide->fresh()->phone01);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    /** @test */
    public function delete_removes_unreferenced_guide(): void
    {
        $guide = Guide::create($this->minimalData());

        $this->service()->delete($guide, 'actor:1');

        $this->assertDatabaseMissing('guides', ['id' => $guide->id]);
    }

    /** @test */
    public function delete_writes_audit_log_entry(): void
    {
        $guide = Guide::create($this->minimalData());
        $id    = $guide->id;

        $this->service()->delete($guide, 'actor:1');

        $log = StaffAuditLog::where('entity_type', 'guide')
            ->where('entity_id', $id)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('phone01', $log->changes);
    }

    /** @test */
    public function delete_throws_when_guide_has_bookings(): void
    {
        $guide = Guide::create($this->minimalData());

        DB::table('bookings')->insert([
            'driver_id'       => 1,
            'guide_id'        => $guide->id,
            'tour_id'         => 1,
            'guest_id'        => 1,
            'grand_total'     => 0,
            'amount'          => 0,
            'payment_method'  => 'cash',
            'payment_status'  => 'unpaid',
            'group_name'      => 'Test',
            'pickup_location' => 'TBD',
            'dropoff_location'=> 'TBD',
            'booking_status'  => 'pending',
            'booking_source'  => 'test',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be deleted/');

        $this->service()->delete($guide, 'actor:1');
    }
}
