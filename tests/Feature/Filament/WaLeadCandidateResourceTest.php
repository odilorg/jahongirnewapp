<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\WaLeadCandidateResource;
use App\Filament\Resources\WaLeadCandidateResource\Pages\ListWaLeadCandidates;
use App\Models\User;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WaLeadCandidateResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin');
        Role::findOrCreate('admin');
        Role::findOrCreate('manager');
        Role::findOrCreate('cashier');
    }

    private function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function candidate(array $attrs = []): WaLeadCandidate
    {
        return WaLeadCandidate::create(array_merge([
            'phone' => '99890' . random_int(1000000, 9999999), 'inbound_count' => 1, 'outbound_count' => 0,
            'status' => WaLeadCandidate::STATUS_REVIEW, 'classification' => 'genuine_tour_inquiry',
            'first_messages' => 'price for 2 pax yurt camp?',
        ], $attrs));
    }

    /** @test */
    public function resource_is_read_only_no_create_edit_delete(): void
    {
        $this->assertSame(['index'], array_keys(WaLeadCandidateResource::getPages()));
        $this->assertFalse(WaLeadCandidateResource::canCreate());
        $this->actingAs($this->userWithRole('super_admin'));
        $c = $this->candidate();
        $this->assertFalse(WaLeadCandidateResource::canEdit($c));
        $this->assertFalse(WaLeadCandidateResource::canDelete($c));
    }

    /** @test */
    public function admin_and_manager_can_view_cashier_cannot(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $this->assertTrue(WaLeadCandidateResource::canViewAny());

        $this->actingAs($this->userWithRole('manager'));
        $this->assertTrue(WaLeadCandidateResource::canViewAny());

        $this->actingAs($this->userWithRole('cashier'));
        $this->assertFalse(WaLeadCandidateResource::canViewAny());
    }

    /** @test */
    public function list_page_loads_for_admin_and_shows_candidate(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $c = $this->candidate();

        Livewire::test(ListWaLeadCandidates::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$c]);
    }
}
