<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadPriority;
use App\Models\Lead;

class SetLeadPriority
{
    public function handle(Lead $lead, LeadPriority $priority): Lead
    {
        if ($lead->priority === $priority) {
            return $lead;
        }

        $lead->update(['priority' => $priority->value]);

        return $lead->fresh();
    }
}
