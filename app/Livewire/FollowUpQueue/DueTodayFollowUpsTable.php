<?php

declare(strict_types=1);

namespace App\Livewire\FollowUpQueue;

use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Builder;

class DueTodayFollowUpsTable extends AbstractFollowUpsTable
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
            ->dueToday()
            ->orderByPriorityThenDue();
    }

    protected function sectionLabel(): string
    {
        return 'Due today';
    }

    protected function sectionColor(): string
    {
        return 'warning';
    }

    protected function pollSeconds(): int
    {
        return 60;
    }

    protected function emptyHeading(): string
    {
        return 'Nothing scheduled for today.';
    }
}
