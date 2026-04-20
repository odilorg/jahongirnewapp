<?php

declare(strict_types=1);

namespace App\Livewire\FollowUpQueue;

use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Builder;

class UpcomingFollowUpsTable extends AbstractFollowUpsTable
{
    protected function rowsQuery(): Builder
    {
        return LeadFollowUp::query()
            ->join('leads', 'leads.id', '=', 'lead_followups.lead_id')
            ->select('lead_followups.*')
            ->selectRaw(LeadFollowUp::EFFECTIVE_DUE_SQL.' as effective_due')
            ->with([
                'lead:id,name,priority,status,assigned_to,phone,email,whatsapp_number',
                'lead.assignee:id,name',
                'lead.latestInteraction',
            ])
            ->upcoming(7)
            ->orderByPriorityThenDue();
    }

    protected function sectionLabel(): string
    {
        return 'Upcoming 7 days';
    }

    protected function sectionColor(): string
    {
        return 'info';
    }

    protected function pollSeconds(): int
    {
        return 60;
    }

    protected function emptyHeading(): string
    {
        return 'Clear week ahead.';
    }
}
