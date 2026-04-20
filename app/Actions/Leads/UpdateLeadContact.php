<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Surgical contact-detail edit. Touches only the 4 contact fields; status,
 * priority, notes, assignee are handled by their own actions so this one
 * never accidentally blanks anything else.
 */
class UpdateLeadContact
{
    private const EDITABLE = ['name', 'phone', 'email', 'whatsapp_number'];

    public function handle(Lead $lead, array $data): Lead
    {
        $changes = array_intersect_key($data, array_flip(self::EDITABLE));

        if ($changes === []) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $changes) {
            $lead->update($changes);

            return $lead->fresh();
        });
    }
}
