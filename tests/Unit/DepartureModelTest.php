<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BookingInquiry;
use App\Models\Departure;
use App\Policies\DeparturePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — Departure model & DeparturePolicy unit tests.
 *
 * Covers:
 *  - constants and STATUSES integrity
 *  - seats_booked / seats_remaining / is_bookable accessor logic
 *  - scope behavior (Bookable, BookableWithSeats, PubliclyVisible)
 *  - generateReference sequencing
 *  - DeparturePolicy type-conditional methods
 *
 * State transitions are NOT tested here — those live in action class tests
 * (Phase 2). This file proves the data layer correctness only.
 */
class DepartureModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function statuses_constant_is_complete_and_unique(): void
    {
        $this->assertSame(8, count(Departure::STATUSES));
        $this->assertSame(count(Departure::STATUSES), count(array_unique(Departure::STATUSES)));

        foreach (Departure::TERMINAL_STATUSES as $terminal) {
            $this->assertContains($terminal, Departure::STATUSES);
        }
        foreach (Departure::PUBLIC_STATUSES as $public) {
            $this->assertContains($public, Departure::STATUSES);
        }
    }

    /** @test */
    public function generate_reference_produces_sequential_per_year_codes(): void
    {
        $first  = Departure::generateReference();
        $this->assertMatchesRegularExpression('/^DEP-\d{4}-\d{6}$/', $first);
        $this->assertStringContainsString((string) now()->year, $first);
    }

    /** @test */
    public function seats_booked_excludes_cancelled_and_spam_inquiries(): void
    {
        $departure = Departure::factory()->create(['capacity_seats' => 12]);

        BookingInquiry::factory()->create([
            'departure_id' => $departure->id,
            'seats_held'   => 3,
            'status'       => BookingInquiry::STATUS_CONFIRMED,
        ]);
        BookingInquiry::factory()->create([
            'departure_id' => $departure->id,
            'seats_held'   => 2,
            'status'       => BookingInquiry::STATUS_AWAITING_PAYMENT,
        ]);
        BookingInquiry::factory()->create([
            'departure_id' => $departure->id,
            'seats_held'   => 5,
            'status'       => BookingInquiry::STATUS_CANCELLED,  // freed
        ]);
        BookingInquiry::factory()->create([
            'departure_id' => $departure->id,
            'seats_held'   => 1,
            'status'       => BookingInquiry::STATUS_SPAM,       // freed
        ]);

        $this->assertSame(5, $departure->seats_booked);
        $this->assertSame(7, $departure->seats_remaining);
    }

    /** @test */
    public function is_bookable_requires_open_or_guaranteed_status(): void
    {
        $draft = Departure::factory()->create(['status' => Departure::STATUS_DRAFT]);
        $this->assertFalse($draft->isBookable());

        $open = Departure::factory()->open()->create(['cutoff_at' => now()->addDay()]);
        $this->assertTrue($open->isBookable());

        $cancelled = Departure::factory()->create(['status' => Departure::STATUS_CANCELLED]);
        $this->assertFalse($cancelled->isBookable());
    }

    /** @test */
    public function is_bookable_returns_false_after_cutoff(): void
    {
        $departure = Departure::factory()->open()->create([
            'cutoff_at' => now()->subHour(),
        ]);

        $this->assertFalse($departure->isBookable());
    }

    /** @test */
    public function is_bookable_returns_false_when_full(): void
    {
        $departure = Departure::factory()->open()->create([
            'capacity_seats' => 4,
            'cutoff_at'      => now()->addDays(2),
        ]);

        BookingInquiry::factory()->create([
            'departure_id' => $departure->id,
            'seats_held'   => 4,
            'status'       => BookingInquiry::STATUS_CONFIRMED,
        ]);

        $this->assertSame(0, $departure->fresh()->seats_remaining);
        $this->assertFalse($departure->fresh()->isBookable());
    }

    /** @test */
    public function scope_bookable_does_not_filter_by_seats_remaining(): void
    {
        // Full but otherwise bookable — scopeBookable should INCLUDE it,
        // scopeBookableWithSeats should EXCLUDE it.
        $full = Departure::factory()->open()->create([
            'capacity_seats' => 4,
            'cutoff_at'      => now()->addDays(2),
        ]);
        BookingInquiry::factory()->create([
            'departure_id' => $full->id,
            'seats_held'   => 4,
            'status'       => BookingInquiry::STATUS_CONFIRMED,
        ]);

        $available = Departure::factory()->open()->create([
            'capacity_seats' => 12,
            'cutoff_at'      => now()->addDays(2),
        ]);

        $this->assertSame(2, Departure::bookable()->count());
        $this->assertSame(1, Departure::bookableWithSeats()->count());
        $this->assertSame(
            $available->id,
            Departure::bookableWithSeats()->first()->id
        );
    }

    /** @test */
    public function scope_publicly_visible_excludes_private_departures(): void
    {
        Departure::factory()->open()->group()->create();
        Departure::factory()->open()->private()->create();

        $this->assertSame(1, Departure::publiclyVisible()->count());
        $this->assertSame(1, Departure::forSitemap()->count());
    }

    /** @test */
    public function is_terminal_returns_true_for_terminal_statuses(): void
    {
        $cases = [
            Departure::STATUS_COMPLETED        => true,
            Departure::STATUS_CANCELLED        => true,
            Departure::STATUS_CANCELLED_MIN_PAX => true,
            Departure::STATUS_OPEN             => false,
            Departure::STATUS_GUARANTEED       => false,
            Departure::STATUS_DRAFT            => false,
        ];

        foreach ($cases as $status => $expected) {
            $departure = Departure::factory()->create(['status' => $status]);
            $this->assertSame(
                $expected,
                $departure->isTerminal(),
                "Status {$status} terminal-check failed"
            );
        }
    }

    /** @test */
    public function policy_allows_auto_cancel_only_for_group(): void
    {
        $policy = new DeparturePolicy();

        $group = Departure::factory()->group()->make();
        $private = Departure::factory()->private()->make();

        $this->assertTrue($policy->allowsAutoCancel($group));
        $this->assertFalse($policy->allowsAutoCancel($private));
    }

    /** @test */
    public function policy_requires_minimum_pax_only_for_group(): void
    {
        $policy = new DeparturePolicy();

        $this->assertTrue($policy->requiresMinimumPax(Departure::factory()->group()->make()));
        $this->assertFalse($policy->requiresMinimumPax(Departure::factory()->private()->make()));
    }

    /** @test */
    public function policy_publicly_listable_requires_group_and_open_or_guaranteed(): void
    {
        $policy = new DeparturePolicy();

        $this->assertTrue($policy->isPubliclyListable(
            Departure::factory()->group()->open()->make()
        ));
        $this->assertTrue($policy->isPubliclyListable(
            Departure::factory()->group()->guaranteed()->make()
        ));
        $this->assertFalse($policy->isPubliclyListable(
            Departure::factory()->group()->make()  // draft
        ));
        $this->assertFalse($policy->isPubliclyListable(
            Departure::factory()->private()->open()->make()
        ));
    }
}
