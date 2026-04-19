<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadStatus;
use App\Exceptions\Leads\InvalidLeadStatusTransition;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the lead state machine and records every transition as an
 * internal-note interaction so the timeline doubles as an audit trail.
 */
class TransitionLeadStatus
{
    private const ALLOWED = [
        'new'              => ['contacted', 'lost'],
        'contacted'        => ['qualified', 'waiting_guest', 'lost'],
        'qualified'        => ['quoted', 'waiting_guest', 'waiting_internal', 'lost'],
        'quoted'           => ['waiting_guest', 'tentative', 'converted', 'lost'],
        'waiting_guest'    => ['quoted', 'tentative', 'converted', 'lost'],
        'waiting_internal' => ['quoted', 'tentative', 'converted', 'lost'],
        'tentative'        => ['quoted', 'converted', 'lost'],
        'converted'        => [],
        'lost'             => [],
    ];

    public function handle(Lead $lead, LeadStatus $to, ?string $waitingReason = null): Lead
    {
        if ($lead->status === $to) {
            return $lead;
        }

        $from = $lead->status->value;

        if (! in_array($to->value, self::ALLOWED[$from] ?? [], true)) {
            throw new InvalidLeadStatusTransition(
                "Lead {$lead->id}: {$from} → {$to->value} is not a permitted transition."
            );
        }

        return DB::transaction(function () use ($lead, $from, $to, $waitingReason) {
            $lead->update([
                'status'         => $to->value,
                'waiting_reason' => in_array($to->value, ['waiting_guest', 'waiting_internal'], true)
                    ? $waitingReason
                    : null,
            ]);

            $lead->interactions()->create([
                'user_id'     => auth()->id(),
                'channel'     => LeadInteractionChannel::InternalNote->value,
                'direction'   => LeadInteractionDirection::Internal->value,
                'body'        => "Status: {$from} → {$to->value}"
                              .($waitingReason ? " — {$waitingReason}" : ''),
                'occurred_at' => now(),
            ]);

            return $lead->fresh();
        });
    }
}
