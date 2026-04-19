<?php

declare(strict_types=1);

namespace App\Filament\Pages\FollowUpQueue;

use App\Models\LeadFollowUp;
use Illuminate\Database\Eloquent\Builder;

class OverdueFollowUpsTable extends AbstractFollowUpsTable
{
    protected function rowsQuery(): Builder
    {
        return LeadFollowUp::query()
            ->join('leads', 'leads.id', '=', 'lead_followups.lead_id')
            ->select('lead_followups.*')
            ->selectRaw(LeadFollowUp::EFFECTIVE_DUE_SQL.' as effective_due')
            ->with(['lead:id,name,priority,assigned_to', 'lead.assignee:id,name'])
            ->overdue()
            ->orderByPriorityThenDue();
    }

    protected function sectionLabel(): string
    {
        return 'Overdue';
    }

    protected function sectionColor(): string
    {
        return 'danger';
    }

    protected function pollSeconds(): int
    {
        return 30;
    }

    protected function emptyHeading(): string
    {
        return 'Nothing overdue. Keep it that way.';
    }
}
