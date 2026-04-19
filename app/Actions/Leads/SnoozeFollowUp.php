<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Models\LeadFollowUp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class SnoozeFollowUp
{
    public function handle(LeadFollowUp $followUp, CarbonInterface $until): LeadFollowUp
    {
        if ($followUp->status !== LeadFollowUpStatus::Open) {
            throw new LogicException('Cannot snooze a follow-up that is not open.');
        }

        if ($until->lte(now())) {
            throw new InvalidArgumentException('snoozed_until must be in the future.');
        }

        return DB::transaction(function () use ($followUp, $until) {
            $followUp->update(['snoozed_until' => $until]);

            return $followUp->fresh();
        });
    }
}
