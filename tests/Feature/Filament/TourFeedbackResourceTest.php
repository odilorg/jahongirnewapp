<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\TourFeedbackResource;
use App\Filament\Resources\TourFeedbackResource\Pages\ListTourFeedback;
use App\Filament\Resources\TourFeedbackResource\Pages\ViewTourFeedback;
use App\Models\BookingInquiry;
use App\Models\TourFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Read-only invariants for TourFeedbackResource.
 *
 * The resource exists so admins can analyse guest sentiment — never to
 * mutate a guest's submission. These tests pin three contracts:
 *
 *   1. The resource is structurally read-only (no create/edit/delete pages
 *      registered, no policy method returns true for create/edit/delete).
 *   2. super_admin and admin can see it; cashier (and other ops roles) cannot.
 *   3. The list table loads for an authorised admin and shows the seeded
 *      feedback row — proves the read path itself isn't broken by the
 *      role gate.
 */
final class TourFeedbackResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin');
        Role::findOrCreate('admin');
        Role::findOrCreate('cashier');
        Role::findOrCreate('manager');
    }

    /** @test */
    public function resource_is_structurally_read_only(): void
    {
        // No create page. Only index + view are registered.
        $pages = array_keys(TourFeedbackResource::getPages());
        sort($pages);
        $this->assertSame(['index', 'view'], $pages);

        // Policy methods deny all mutation regardless of caller, even when
        // the caller is super_admin (the highest-privilege role).
        $superAdmin = $this->userWithRole('super_admin');
        $this->actingAs($superAdmin);

        $sample = $this->seedFeedback();

        $this->assertFalse(TourFeedbackResource::canCreate(), 'canCreate must be false');
        $this->assertFalse(TourFeedbackResource::canEdit($sample), 'canEdit must be false');
        $this->assertFalse(TourFeedbackResource::canDelete($sample), 'canDelete must be false');
        $this->assertFalse(TourFeedbackResource::canDeleteAny(), 'canDeleteAny must be false');
    }

    /** @test */
    public function super_admin_can_view_resource(): void
    {
        $this->actingAs($this->userWithRole('super_admin'));
        $this->assertTrue(TourFeedbackResource::canViewAny());
    }

    /** @test */
    public function admin_can_view_resource(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $this->assertTrue(TourFeedbackResource::canViewAny());
    }

    /** @test */
    public function cashier_cannot_view_resource(): void
    {
        $this->actingAs($this->userWithRole('cashier'));
        $this->assertFalse(TourFeedbackResource::canViewAny());
    }

    /** @test */
    public function manager_cannot_view_resource_unless_explicitly_added(): void
    {
        // Pinning current scope: only super_admin + admin. If we extend
        // visibility later (e.g. to manager), this test must be updated
        // intentionally — drift must not be silent.
        $this->actingAs($this->userWithRole('manager'));
        $this->assertFalse(TourFeedbackResource::canViewAny());
    }

    /** @test */
    public function list_page_loads_for_admin_and_shows_submitted_feedback(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $feedback = $this->seedFeedback();

        Livewire::test(ListTourFeedback::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$feedback]);
    }

    /** @test */
    public function view_page_loads_for_admin(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        $feedback = $this->seedFeedback();

        Livewire::test(ViewTourFeedback::class, ['record' => $feedback->getKey()])
            ->assertSuccessful();
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Minimal submitted feedback row tied to an inquiry. Goes through the
     * BookingInquiry::create path so reference auto-numbering and required
     * columns stay honest if those rules drift later.
     */
    private function seedFeedback(): TourFeedback
    {
        $inquiry = BookingInquiry::create([
            'reference'          => BookingInquiry::generateReference(),
            'source'             => 'manual',
            'tour_name_snapshot' => 'Test Tour for Feedback',
            'customer_name'      => 'Test Guest',
            'customer_phone'     => '',
            'people_adults'      => 2,
            'travel_date'        => now()->subDays(3)->toDateString(),
            'status'             => 'confirmed',
        ]);

        return TourFeedback::create([
            'inquiry_id'     => $inquiry->id,
            'token'          => TourFeedback::generateToken(),
            'source'         => 'whatsapp',
            'opener_index'   => 0,
            'overall_rating' => 5,
            'driver_rating'  => 5,
            'comments'       => 'Loved every minute.',
            'submitted_at'   => now()->subHours(2),
        ]);
    }
}
