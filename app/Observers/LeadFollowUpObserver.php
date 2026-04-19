<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LeadFollowUp;

/**
 * Lead CRM Phase 1 — keeps lead.next_followup_at in sync.
 *
 * Denormalized because the operator queue view sorts by it on every page
 * load; recomputing via a subquery would be wasteful.
 */
class LeadFollowUpObserver
{
    public function saved(LeadFollowUp $followUp): void
    {
        $followUp->lead?->refreshNextFollowupAt();
    }

    public function deleted(LeadFollowUp $followUp): void
    {
        $followUp->lead?->refreshNextFollowupAt();
    }
}
