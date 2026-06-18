<?php

declare(strict_types=1);

namespace App\Actions\Calendar\Save;

use App\Actions\Calendar\Support\CalendarActionResult;
use App\Models\BookingInquiry;
use App\Support\OperationalFlagExtractor;
use Illuminate\Support\Facades\DB;

/**
 * Save one guest-context notes field (driver OR accommodation) from the
 * calendar slide-over.
 *
 * Two recipient-specific fields share this single write path:
 *   - operational_notes   → sent to the assigned DRIVER/guide
 *   - accommodation_notes → sent to the CAMP/HOTEL stay supplier
 *
 * Each field has its own Save button, but the four operational flags
 * (♿🍃🗣🎉) are always derived from the UNION of BOTH fields: the new value
 * for the field being saved + the currently-stored value of the other field.
 * So moving a dietary need into the accommodation field never drops the 🍃
 * icon, and saving one field never wipes a flag the other field set. Saves
 * are sequential operator clicks reading the persisted counterpart, so there
 * is no stale-union race.
 *
 * Owns: length cap (300, server-side trust boundary), no-op short-circuit,
 * single-transaction flag derivation + forceFill + audit line to
 * internal_notes (capped at 8 KB).
 */
final class QuickSaveOperationalNotesAction
{
    public const MAX_NOTES_LENGTH    = 300;
    public const MAX_AUDIT_NOTE_SIZE = 500;
    public const MAX_INTERNAL_NOTES  = 8000;

    public const FIELD_DRIVER        = 'operational_notes';
    public const FIELD_ACCOMMODATION = 'accommodation_notes';

    /** Human label per field, used in cap errors + audit lines. */
    private const FIELD_LABELS = [
        self::FIELD_DRIVER        => 'driver notes',
        self::FIELD_ACCOMMODATION => 'accommodation notes',
    ];

    /**
     * @param  array{field?: string, notes?: ?string, operator_id?: int|null, operator_name?: ?string}  $data
     */
    public function handle(BookingInquiry $inquiry, array $data): CalendarActionResult
    {
        // Whitelist the target column — never trust a caller-supplied field.
        $field = $data['field'] ?? self::FIELD_DRIVER;
        if (! array_key_exists($field, self::FIELD_LABELS)) {
            return CalendarActionResult::failure('Unknown notes field.');
        }
        $label = self::FIELD_LABELS[$field];
        $otherField = $field === self::FIELD_DRIVER
            ? self::FIELD_ACCOMMODATION
            : self::FIELD_DRIVER;

        // Normalise: trim + null-on-empty so clearing the field works.
        $newNotes = $this->normalize($data['notes'] ?? null);

        // Server-side length cap (textarea maxlength is UX guidance only).
        if ($newNotes !== null && mb_strlen($newNotes) > self::MAX_NOTES_LENGTH) {
            return CalendarActionResult::failure(
                ucfirst($label) . ' exceed ' . self::MAX_NOTES_LENGTH . ' characters.',
            );
        }

        // No-op short-circuit on the field being saved.
        $oldNotes = $this->normalize($inquiry->{$field});
        if ($oldNotes === $newNotes) {
            return CalendarActionResult::success('No changes to ' . $label . '.');
        }

        $operatorName = $data['operator_name'] ?? 'system';

        DB::transaction(function () use ($inquiry, $field, $otherField, $newNotes, $oldNotes, $label, $operatorName): void {
            // Flags derive from the UNION of both fields (order irrelevant —
            // the extractor is keyword-based). Use the new value for the field
            // being saved and the stored value of the other field.
            $otherNotes = $this->normalize($inquiry->{$otherField});
            $union = trim(((string) $newNotes) . ' ' . ((string) $otherNotes));
            $flags = OperationalFlagExtractor::extract($union !== '' ? $union : null);

            $stamp      = now()->format('Y-m-d H:i');
            $oldDisplay = $this->truncate($oldNotes ?? '<empty>', 120);
            $newDisplay = $this->truncate($newNotes ?? '<empty>', 120);
            $auditLine  = "[{$stamp} {$operatorName}] {$label}: \"{$oldDisplay}\" → \"{$newDisplay}\"";
            $auditLine  = $this->truncate($auditLine, self::MAX_AUDIT_NOTE_SIZE);

            $existing = $inquiry->internal_notes ? $inquiry->internal_notes . "\n\n" : '';
            $merged   = $existing . $auditLine;
            if (mb_strlen($merged) > self::MAX_INTERNAL_NOTES) {
                $merged = mb_substr($merged, -self::MAX_INTERNAL_NOTES);
            }

            $inquiry->forceFill([
                $field                   => $newNotes,
                'has_dietary_flag'       => $flags['dietary'],
                'has_accessibility_flag' => $flags['accessibility'],
                'has_language_flag'      => $flags['language'],
                'has_occasion_flag'      => $flags['occasion'],
                'internal_notes'         => $merged,
            ])->save();
        });

        return CalendarActionResult::success(ucfirst($label) . ' saved');
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1) . '…';
    }
}
