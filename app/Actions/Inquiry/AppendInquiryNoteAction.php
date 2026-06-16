<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\Models\BookingInquiry;
use Illuminate\Validation\ValidationException;

/**
 * Append a single timestamped, attributed line to an inquiry's internal_notes.
 *
 * Canonical note-append for the tour-agent path. internal_notes is append-only
 * audit text — never overwrite. forceFill keeps the write safe against a future
 * $fillable regression (per the operational-write rule).
 */
class AppendInquiryNoteAction
{
    public function execute(BookingInquiry $inquiry, string $note, string $actor = 'tour-agent'): BookingInquiry
    {
        $clean = trim($note);
        if ($clean === '') {
            throw ValidationException::withMessages(['note' => 'Note text is required.']);
        }

        $existing = (string) $inquiry->internal_notes;
        $line = self::formatLine($clean, $actor);

        $inquiry->forceFill([
            'internal_notes' => $existing === '' ? $line : $existing."\n".$line,
        ])->save();

        return $inquiry->refresh();
    }

    /** Shared format so every agent-authored note reads identically. */
    public static function formatLine(string $note, string $actor): string
    {
        return sprintf('[%s] [%s] %s', now()->format('Y-m-d H:i'), $actor, trim($note));
    }
}
