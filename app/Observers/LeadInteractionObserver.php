<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LeadInteraction;

/**
 * Lead CRM Phase 1 — append-only interaction hygiene + denormalization.
 *
 * - Defaults occurred_at to now when the caller omits it.
 * - Stamps lead.last_interaction_at after insert so queue views stay fresh
 *   without every action needing to remember.
 */
class LeadInteractionObserver
{
    public function creating(LeadInteraction $interaction): void
    {
        $interaction->occurred_at ??= now();
    }

    public function created(LeadInteraction $interaction): void
    {
        $interaction->lead()->update([
            'last_interaction_at' => $interaction->occurred_at,
        ]);
    }
}
