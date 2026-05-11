<?php

declare(strict_types=1);

namespace App\Actions\HR;

use App\Models\JobCandidate;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a candidate's CV from the private disk with a humanised
 * download filename (`cv-{id}-{full_name}.{ext}`).
 *
 * Lives here (not in the Filament closure) per CLAUDE.md hard line
 * (closures > 10 LOC must be extracted). Filament's row action
 * checks role + non-null cv_path before invoking; this Action
 * defends against the file having been removed off-disk since the
 * row was rendered (rare but possible if storage was pruned).
 *
 * Phase 1, 2026-05-11.
 */
final class DownloadCandidateCvAction
{
    /**
     * @return BinaryFileResponse|StreamedResponse|null Null when the file
     *                                                  is missing on disk (caller already showed a notification).
     */
    public function execute(JobCandidate $candidate): BinaryFileResponse|StreamedResponse|null
    {
        if ($candidate->cv_path === null || ! Storage::disk('local')->exists($candidate->cv_path)) {
            Notification::make()
                ->title('Файл не найден')
                ->danger()
                ->send();

            return null;
        }

        $ext = pathinfo($candidate->cv_path, PATHINFO_EXTENSION);
        $safeName = preg_replace('/\W+/u', '_', (string) $candidate->full_name);
        $filename = "cv-{$candidate->id}-{$safeName}.{$ext}";

        return Storage::disk('local')->download($candidate->cv_path, $filename);
    }
}
