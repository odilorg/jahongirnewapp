<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational guest-context flags — derived booleans from operational_notes.
 *
 * Rationale: customer-supplied context (dietary, accessibility, language,
 * special occasion) lives in `operational_notes` as free text. Operators
 * need to *scan* the calendar list for these signals at a glance, and
 * future filters need a queryable surface. Storing 4 derived booleans
 * gives both — without locking us into a structured-fields-only schema.
 *
 * Source of truth for the derivation: `App\Support\OperationalFlagExtractor`.
 * Backfill: `php artisan inquiries:backfill-operational-flags`.
 *
 * Index design: composite on (travel_date, has_dietary_flag,
 * has_accessibility_flag, has_language_flag, has_occasion_flag) supports
 * the calendar-list query pattern "all bookings on a given date with any
 * flag", which is the dominant read-side use.
 *
 * Idempotent guards via `Schema::hasColumn` so re-running the migration
 * (manual replays, environment-mismatch recoveries) is safe.
 */
return new class extends Migration {
    private const COLUMNS = [
        'has_dietary_flag',
        'has_accessibility_flag',
        'has_language_flag',
        'has_occasion_flag',
    ];

    private const INDEX_NAME = 'booking_inquiries_calendar_flags_idx';

    public function up(): void
    {
        Schema::table('booking_inquiries', function (Blueprint $table) {
            $existing = Schema::getColumnListing('booking_inquiries');
            $after    = 'operational_notes';

            foreach (self::COLUMNS as $col) {
                if (in_array($col, $existing, true)) {
                    continue;
                }
                $table->boolean($col)->default(false)->nullable()->after($after);
                $after = $col;
            }
        });

        // Composite index — guarded so re-runs don't error.
        if (! $this->indexExists('booking_inquiries', self::INDEX_NAME)) {
            Schema::table('booking_inquiries', function (Blueprint $table) {
                $table->index(
                    [
                        'travel_date',
                        'has_dietary_flag',
                        'has_accessibility_flag',
                        'has_language_flag',
                        'has_occasion_flag',
                    ],
                    self::INDEX_NAME,
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('booking_inquiries', self::INDEX_NAME)) {
            Schema::table('booking_inquiries', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }

        Schema::table('booking_inquiries', function (Blueprint $table) {
            $existing = Schema::getColumnListing('booking_inquiries');
            $drop = array_values(array_intersect(self::COLUMNS, $existing));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index],
        );
        return $rows !== [];
    }
};
