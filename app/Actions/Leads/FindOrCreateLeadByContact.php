<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Enums\LeadSource;
use App\Exceptions\Leads\AmbiguousLeadMatchException;
use App\Models\Lead;
use InvalidArgumentException;

/**
 * Resolves an inbound contact to a single Lead.
 *
 * Progressive matching: try the strongest identifier first (telegram_chat_id),
 * stop as soon as any field yields ≥1 match. Prevents false ambiguity when
 * the caller passes several identifiers that happen to collide with different
 * leads across the table.
 *
 * Never merges automatically. Ambiguous matches throw so a human resolves them.
 */
class FindOrCreateLeadByContact
{
    private const PRIORITY = [
        'telegram_chat_id',
        'whatsapp_number',
        'phone',
        'email',
    ];

    public function handle(array $contact, array $defaults = []): Lead
    {
        $fields = array_filter([
            'telegram_chat_id' => $contact['telegram_chat_id'] ?? null,
            'whatsapp_number'  => $contact['whatsapp_number']  ?? null,
            'phone'            => $contact['phone']            ?? null,
            'email'            => $contact['email']            ?? null,
        ]);

        if (empty($fields)) {
            throw new InvalidArgumentException('At least one contact field is required.');
        }

        foreach (self::PRIORITY as $field) {
            if (empty($fields[$field])) {
                continue;
            }

            $matches = Lead::where($field, $fields[$field])->get();

            if ($matches->count() > 1) {
                throw new AmbiguousLeadMatchException(
                    "Multiple leads share {$field}={$fields[$field]} — ids=[".$matches->pluck('id')->implode(',').']'
                );
            }

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return Lead::create(array_merge(
            ['source' => LeadSource::Other->value],
            $defaults,
            $fields,
        ));
    }
}
