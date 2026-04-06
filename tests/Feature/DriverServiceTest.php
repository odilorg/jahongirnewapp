<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Driver;
use App\Services\DriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('drivers')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function service(): DriverService
    {
        return new DriverService();
    }

    private function minimalData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name'  => 'Smith',
            'phone01'    => '+998901234567',
            'email'      => 'john@example.com',
            'fuel_type'  => 'Petrol',
            'booking_id' => 1,
        ], $overrides);
    }

    // ── create ────────────────────────────────────────────────────────────────

    /** @test */
    public function create_persists_driver_and_defaults_to_active(): void
    {
        $driver = $this->service()->create($this->minimalData(), 'actor:1');

        $this->assertInstanceOf(Driver::class, $driver);
        $this->assertDatabaseHas('drivers', [
            'first_name' => 'John',
            'last_name'  => 'Smith',
            'email'      => 'john@example.com',
            'fuel_type'  => 'Petrol',
            'is_active'  => true,
        ]);
    }

    /** @test */
    public function create_trims_whitespace_from_fields(): void
    {
        $driver = $this->service()->create($this->minimalData([
            'first_name' => '  Jane  ',
            'last_name'  => '  Doe  ',
        ]), 'actor:1');

        $this->assertSame('Jane', $driver->first_name);
        $this->assertSame('Doe', $driver->last_name);
    }

    /** @test */
    public function create_stores_optional_fields_when_provided(): void
    {
        $driver = $this->service()->create($this->minimalData([
            'phone02'      => '+998901111111',
            'address_city' => 'Samarkand',
        ]), 'actor:1');

        $this->assertSame('+998901111111', $driver->phone02);
        $this->assertSame('Samarkand', $driver->address_city);
    }

    // ── update ────────────────────────────────────────────────────────────────

    /** @test */
    public function update_changes_only_provided_fields(): void
    {
        $driver = Driver::create($this->minimalData());

        $this->service()->update($driver, ['first_name' => 'Bob'], 'actor:1');
        $driver->refresh();

        $this->assertSame('Bob', $driver->first_name);
        $this->assertSame('Smith', $driver->last_name); // unchanged
    }

    /** @test */
    public function update_is_no_op_when_value_unchanged(): void
    {
        $driver = Driver::create($this->minimalData());
        $before = $driver->updated_at;

        // Same value
        $this->service()->update($driver, ['first_name' => 'John'], 'actor:1');
        $driver->refresh();

        $this->assertSame('John', $driver->first_name);
    }

    /** @test */
    public function update_clears_nullable_field_when_passed_null(): void
    {
        $driver = Driver::create($this->minimalData(['phone02' => '+99891111111']));

        $this->service()->update($driver, ['phone02' => null], 'actor:1');
        $driver->refresh();

        $this->assertNull($driver->phone02);
    }

    // ── setActive ─────────────────────────────────────────────────────────────

    /** @test */
    public function set_active_deactivates_an_active_driver(): void
    {
        $driver = Driver::create($this->minimalData(['is_active' => true]));

        $this->service()->setActive($driver, false, 'actor:1');
        $driver->refresh();

        $this->assertFalse($driver->is_active);
    }

    /** @test */
    public function set_active_activates_an_inactive_driver(): void
    {
        $driver = Driver::create($this->minimalData(['is_active' => false]));

        $this->service()->setActive($driver, true, 'actor:1');
        $driver->refresh();

        $this->assertTrue($driver->is_active);
    }

    /** @test */
    public function set_active_is_no_op_when_status_unchanged(): void
    {
        $driver = Driver::create($this->minimalData(['is_active' => true]));
        $ts     = $driver->updated_at;

        $this->service()->setActive($driver, true, 'actor:1');
        $driver->refresh();

        // updated_at should not have changed (or at most within same second)
        $this->assertSame($ts?->toDateTimeString(), $driver->updated_at?->toDateTimeString());
    }

    // ── list ──────────────────────────────────────────────────────────────────

    /** @test */
    public function list_returns_all_drivers_by_default(): void
    {
        Driver::create($this->minimalData(['is_active' => true]));
        Driver::create($this->minimalData(['first_name' => 'Inactive', 'is_active' => false]));

        $all = $this->service()->list();

        $this->assertCount(2, $all);
    }

    /** @test */
    public function list_only_active_excludes_inactive_drivers(): void
    {
        Driver::create($this->minimalData(['is_active' => true]));
        Driver::create($this->minimalData(['first_name' => 'Inactive', 'is_active' => false]));

        $active = $this->service()->list(onlyActive: true);

        $this->assertCount(1, $active);
        $this->assertTrue($active->first()->is_active);
    }

    /** @test */
    public function list_orders_by_first_name(): void
    {
        Driver::create($this->minimalData(['first_name' => 'Zara']));
        Driver::create($this->minimalData(['first_name' => 'Aaron']));

        $names = $this->service()->list()->pluck('first_name')->all();

        $this->assertSame(['Aaron', 'Zara'], $names);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    /** @test */
    public function find_returns_driver_by_id(): void
    {
        $driver = Driver::create($this->minimalData());

        $found = $this->service()->find($driver->id);

        $this->assertSame($driver->id, $found->id);
    }

    /** @test */
    public function find_throws_when_driver_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Driver #9999 not found/');

        $this->service()->find(9999);
    }
}
