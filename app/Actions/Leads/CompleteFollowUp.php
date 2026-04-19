<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Models\LeadFollowUp;
use Illuminate\Support\Facades\DB;

class CompleteFollowUp
{
    public function handle(LeadFollowUp $followUp, ?int $completedBy = null): LeadFollowUp
    {
        if ($followUp->status === LeadFollowUpStatus::Done) {
            return $followUp;
        }

        return DB::transaction(function () use ($followUp, $completedBy) {
            $followUp->update([
                'status'       => LeadFollowUpStatus::Done->value,
                'completed_at' => now(),
                'completed_by' => $completedBy ?? auth()->id(),
            ]);

            return $followUp->fresh();
        });
    }
}
