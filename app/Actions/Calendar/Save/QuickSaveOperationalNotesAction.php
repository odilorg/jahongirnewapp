<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Support\OperationalFlagExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Save operational guest-context notes from the calendar slide-over.
 *
 * This is the SOLE write path for `operational_notes` on a calendar slide-over
 * edit. Owns the full workflow:
 *   1. Length cap (300 chars, server-side trust boundary per Principle 10).
 *   2. No-op short-circuit if value unchanged after trim — silences rapid-blur
 *      events that would otherwise spam the audit trail.
 *   3. Single transaction:
 *      - Derive 4 boolean flags via `OperationalFlagExtractor` (single source
 *        of truth, also used by the backfill command).
 *      - `forceFill([...])->save()` — per the operational-timestamps rule
 *        (CLAUDE.md), even though columns are fillable, to be future-proof
 *        against rename refactors that drop `$fillable` entries silently.
 *      - Append a one-line audit entry to `internal_notes` with operator name
 *        + timestamp + truncated diff (old → new). Total `internal_notes`
 *        capped at 8 KB to bound storage growth.
 *
 * Concurrency: last-write-wins. Acceptable for free-text notes (matches
 * existing slide-over behaviour for pickup/dropoff). The audit chain in
 * `internal_notes` preserves both writes chronologically.
 */
final class QuickSaveOperationalNotesAction
{
    public const MAX_NOTES_LENGTH    = 300;
    public const MAX_AUDIT_NOTE_SIZE = 500;
    public const MAX_INTERNAL_NOTES  = 8000;

    /**
     * @param  array{notes?: ?string, operator_id?: int|null, operator_name?: ?string}  $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        // Normalise input: trim + null-on-empty so clearing the field works.
        $newNotes = $data['notes'] ?? null;
        if ($newNotes !== null) {
            $newNotes = trim($newNotes);
            if ($newNotes === '') {
                $newNotes = null;
            }
        }

        // Server-side length cap (the maxlength on the textarea is UX guidance
        // only; never trust the frontend per Principle 10).
        if ($newNotes !== null && mb_strlen($newNotes) > self::MAX_NOTES_LENGTH) {
            return CalendarActionResult::failure(
                'Operational notes exceed ' . self::MAX_NOTES_LENGTH . ' characters.',
            );
        }

        // No-op short-circuit — equality after trim/null normalisation.
        $oldNotes = $inquiry->operational_notes !== null ? trim($inquiry->operational_notes) : null;
        if ($oldNotes === '') {
            $oldNotes = null;
        }
        if ($oldNotes === $newNotes) {
            return CalendarActionResult::success('No changes to operational notes.');
        }

        $operatorName = $data['operator_name'] ?? 'system';

        DB::transaction(function () use ($inquiry, $newNotes, $oldNotes, $operatorName): void {
            $flags = OperationalFlagExtractor::extract($newNotes);

            // Build audit line — capped to keep `internal_notes` bounded.
            $stamp = now()->format('Y-m-d H:i');
            $oldDisplay = $this->truncate($oldNotes ?? '<empty>', 120);
            $newDisplay = $this->truncate($newNotes ?? '<empty>', 120);
            $auditLine  = "[{$stamp} {$operatorName}] guest context: \"{$oldDisplay}\" → \"{$newDisplay}\"";
            $auditLine  = $this->truncate($auditLine, self::MAX_AUDIT_NOTE_SIZE);

            $existing = $inquiry->internal_notes ? $inquiry->internal_notes . "\n\n" : '';
            $merged   = $existing . $auditLine;
            // Bound total internal_notes size — keep tail (most recent) on overflow.
            if (mb_strlen($merged) > self::MAX_INTERNAL_NOTES) {
                $merged = mb_substr($merged, -self::MAX_INTERNAL_NOTES);
            }

            $inquiry->forceFill([
                'operational_notes'      => $newNotes,
                'has_dietary_flag'       => $flags['dietary'],
                'has_accessibility_flag' => $flags['accessibility'],
                'has_language_flag'      => $flags['language'],
                'has_occasion_flag'      => $flags['occasion'],
                'internal_notes'         => $merged,
            ])->save();
        });

        return CalendarActionResult::success('Guest context saved');
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }
}
