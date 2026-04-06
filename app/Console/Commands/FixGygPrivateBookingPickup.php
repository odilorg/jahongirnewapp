<?php

namespace App\Console\Commands;

use App\Enums\GygBookingType;
use App\Services\GygPickupResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off remediation: fix bookings created before the GygPickupResolver was
 * introduced, where private-tour bookings were erroneously assigned
 * "Gur Emir Mausoleum" as pickup_location.
 *
 * Safety design:
 *   - Dry-run by default; requires --apply to persist changes.
 *   - Only touches bookings where pickup_location is exactly the wrong value.
 *   - Skips bookings where staff already updated pickup_location manually.
 *   - Idempotent: running twice produces the same result.
 *   - Every change is logged for auditability.
 *
 * Classification strategy (two passes):
 *   Pass 1 — bookings linked to a gyg_inbound_emails row (any email_type):
 *     Use GygPickupResolver with full signal set (tour_type, option_title, tour_name).
 *     Includes amendment and cancellation emails — they carry option_title/tour_name
 *     signals even when tour_type is null.
 *   Pass 2 — orphaned bookings (no linked email):
 *     Fall back to classifyFromTourTitle() using the internal tours.title.
 *     Internal tour titles use explicit group/private vocabulary (e.g.
 *     "Yurt Camp Group Tour", "Yurt Camp Private Tour", "Driver Only").
 */
class FixGygPrivateBookingPickup extends Command
{
    protected $signature = 'gyg:fix-pickup-locations
        {--apply : Persist changes (omit for dry-run)}
        {--limit=500 : Max bookings to process per run}';

    protected $description = 'Backfill: set pickup_location=null for GYG private-tour bookings that were wrongly assigned "Gur Emir Mausoleum"';

    public function __construct(private GygPickupResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = $this->option('apply');
        $limit = (int) $this->option('limit');

        if (! $apply) {
            $this->warn('DRY-RUN mode — no changes will be saved. Pass --apply to persist.');
        }

        $fixed = 0;
        $kept  = 0;

        // ── Pass 1: bookings with a linked gyg_inbound_emails row ──────────
        $this->info('── Pass 1: bookings with linked email ──');

        $linked = DB::table('bookings as b')
            ->join('gyg_inbound_emails as e', 'b.booking_number', '=', 'e.gyg_booking_reference')
            ->where('b.booking_source', 'getyourguide')
            ->where('b.pickup_location', GygPickupResolver::GROUP_MEETING_POINT)
            ->whereIn('e.email_type', ['new_booking', 'amendment', 'cancellation'])
            ->select([
                'b.id         as booking_id',
                'b.booking_number',
                'e.tour_type',
                'e.tour_type_source',
                'e.option_title',
                'e.tour_name',
                'e.guest_name',
            ])
            ->limit($limit)
            ->get();

        $this->info("  Found {$linked->count()} booking(s)");

        foreach ($linked as $row) {
            $type  = $this->resolver->classify(
                tourType:       $row->tour_type,
                tourTypeSource: $row->tour_type_source,
                optionTitle:    $row->option_title,
                tourName:       $row->tour_name,
            );
            $label = "[#{$row->booking_id} {$row->booking_number}] {$row->guest_name} | {$row->option_title}";
            [$fixed, $kept] = $this->applyDecision($row->booking_id, $type, $label, $apply, $fixed, $kept, 'email');
        }

        // ── Pass 2: orphaned bookings — classify via tours.title ───────────
        $this->newLine();
        $this->info('── Pass 2: orphaned bookings (no linked email) ──');

        $linkedRefs = DB::table('gyg_inbound_emails')
            ->whereNotNull('gyg_booking_reference')
            ->pluck('gyg_booking_reference');

        $orphans = DB::table('bookings as b')
            ->join('tours as t', 'b.tour_id', '=', 't.id')
            ->join('guests as g', 'b.guest_id', '=', 'g.id')
            ->where('b.booking_source', 'getyourguide')
            ->where('b.pickup_location', GygPickupResolver::GROUP_MEETING_POINT)
            ->whereNotIn('b.booking_number', $linkedRefs)
            ->select([
                'b.id          as booking_id',
                'b.booking_number',
                't.title        as tour_title',
                DB::raw("CONCAT(g.first_name, ' ', g.last_name) as guest_name"),
            ])
            ->limit($limit)
            ->get();

        $this->info("  Found {$orphans->count()} booking(s)");

        foreach ($orphans as $row) {
            $type  = $this->classifyFromTourTitle($row->tour_title);
            $label = "[#{$row->booking_id} {$row->booking_number}] {$row->guest_name} | {$row->tour_title}";
            [$fixed, $kept] = $this->applyDecision($row->booking_id, $type, $label, $apply, $fixed, $kept, 'tour_title');
        }

        // ── Summary ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('Summary:');
        $this->info("  Fixed (set to null) : {$fixed}");
        $this->info("  Kept (group tour)   : {$kept}");

        if (! $apply && $fixed > 0) {
            $this->newLine();
            $this->warn("Re-run with --apply to persist the {$fixed} fix(es).");
        }

        if ($apply && $fixed > 0) {
            $this->info("✅ {$fixed} booking(s) corrected. Changes logged.");
        }

        return self::SUCCESS;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Classify booking type from the internal tours.title vocabulary.
     *
     * Internal tour titles use explicit group/private vocabulary:
     *   - "Yurt Camp Group Tour"              → Group
     *   - "Yurt Camp Private Tour"            → Private
     *   - "Shahrisabz Day Trip (With Guide)"  → Group (guided = group product)
     *   - "Shahrisabz Day Trip (Driver Only)" → Private (driver-only = no guide)
     *   - "Bukhara Full-Day Guided Tour"      → Group (guided day tour)
     *
     * Keywords checked (case-insensitive):
     *   group signals:   "group", "with guide", "guided"
     *   private signals: "private", "driver only"
     */
    private function classifyFromTourTitle(string $title): GygBookingType
    {
        $lower = strtolower($title);

        if (str_contains($lower, 'group') || str_contains($lower, 'with guide') || str_contains($lower, 'guided')) {
            return GygBookingType::Group;
        }

        if (str_contains($lower, 'private') || str_contains($lower, 'driver only')) {
            return GygBookingType::Private;
        }

        // Conservative fallback: treat as private.
        // Better to prompt hotel request than send wrong meeting point.
        return GygBookingType::Private;
    }

    /**
     * Apply the classification decision to a booking row.
     * Returns updated [$fixed, $kept] counters.
     */
    private function applyDecision(
        int $bookingId,
        GygBookingType $type,
        string $label,
        bool $apply,
        int $fixed,
        int $kept,
        string $source,
    ): array {
        if ($type->isGroup()) {
            $this->line("  ✅ Keep  : {$label} → group [{$source}], keep " . GygPickupResolver::GROUP_MEETING_POINT);
            return [$fixed, $kept + 1];
        }

        $this->warn("  🔧 Fix   : {$label} → {$type->value} [{$source}], set pickup_location = null");

        if ($apply) {
            DB::table('bookings')
                ->where('id', $bookingId)
                ->update([
                    'pickup_location' => null,
                    'updated_at'      => now(),
                ]);

            Log::info('gyg:fix-pickup-locations: cleared wrong pickup', [
                'booking_id'     => $bookingId,
                'classify_source' => $source,
                'type'           => $type->value,
                'label'          => $label,
            ]);
        }

        return [$fixed + 1, $kept];
    }
}
