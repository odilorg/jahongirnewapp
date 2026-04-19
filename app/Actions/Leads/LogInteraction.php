<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\LeadInteraction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LogInteraction
{
    public function handle(Lead $lead, array $data): LeadInteraction
    {
        if (empty($data['channel']) || empty($data['direction']) || empty($data['body'])) {
            throw new InvalidArgumentException('channel, direction and body are required.');
        }

        return DB::transaction(fn () => $lead->interactions()->create([
            'user_id'      => $data['user_id']      ?? auth()->id(),
            'channel'      => $data['channel'],
            'direction'    => $data['direction'],
            'subject'      => $data['subject']      ?? null,
            'body'         => $data['body'],
            'is_important' => $data['is_important'] ?? false,
            'occurred_at'  => $data['occurred_at']  ?? now(),
            'raw_payload'  => $data['raw_payload']  ?? null,
        ]));
    }
}
