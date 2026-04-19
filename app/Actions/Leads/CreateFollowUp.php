<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateFollowUp
{
    public function handle(Lead $lead, array $data): LeadFollowUp
    {
        if (empty($data['due_at'])) {
            throw new InvalidArgumentException('due_at is required.');
        }

        return DB::transaction(fn () => $lead->followUps()->create([
            'lead_interest_id' => $data['lead_interest_id'] ?? null,
            'due_at'           => $data['due_at'],
            'type'             => $data['type'] ?? LeadFollowUpType::Other->value,
            'note'             => $data['note'] ?? null,
            'status'           => LeadFollowUpStatus::Open->value,
        ]));
    }
}
