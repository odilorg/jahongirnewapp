<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Models\WaLeadCandidate;

/**
 * Operator dismisses a WhatsApp candidate (not a lead / handled elsewhere).
 * Requires a reason. Soft state change only — never deletes, never creates an
 * inquiry, never contacts the guest.
 */
class DismissWaCandidate
{
    public function dismiss(WaLeadCandidate $candidate, string $reason, string $by): void
    {
        $candidate->forceFill([
            'status'           => WaLeadCandidate::STATUS_DISMISSED,
            'dismissed_reason' => mb_substr(trim($reason), 0, 191),
            'decided_by'       => $by,
            'decided_at'       => now(),
        ])->save();
    }
}
