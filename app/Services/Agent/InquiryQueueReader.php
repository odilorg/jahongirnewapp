<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\BookingInquiry;

/**
 * Read-only candidate list for the tour-agent poller (M3).
 *
 * Returns inquiries that need a draft, by status, oldest-first. Strictly
 * read-only and PII-free: only id / reference / source / status / dates /
 * party_size are exposed — NO name, phone or email (the poller fetches the
 * full context per-candidate via agent:inquiry-context). Past-dated leads and
 * (optionally) OTA sources are excluded so the agent never drafts dead or
 * already-piped leads.
 */
class InquiryQueueReader
{
    /**
     * @param  list<string>  $statuses
     * @return array<string,mixed>
     */
    public function candidates(array $statuses, int $limit, bool $excludeOta): array
    {
        $query = BookingInquiry::query()
            ->whereIn('status', $statuses)
            ->where(function ($w): void {
                // Skip dead leads: keep only undated or future-dated tours.
                $w->whereNull('travel_date')
                  ->orWhereDate('travel_date', '>=', now()->toDateString());
            })
            ->orderBy('created_at'); // oldest unattended first

        if ($excludeOta) {
            $query->whereNotIn('source', BookingInquiry::OTA_SOURCES);
        }

        $rows = $query->limit($limit)->get([
            'id', 'reference', 'source', 'status', 'created_at', 'updated_at',
            'travel_date', 'people_adults', 'people_children',
        ]);

        return [
            'count' => $rows->count(),
            'inquiries' => $rows->map(fn (BookingInquiry $i): array => [
                'id' => $i->id,
                'reference' => $i->reference,
                'source' => $i->source,
                'status' => $i->status,
                'created_at' => $i->created_at?->toIso8601String(),
                'updated_at' => $i->updated_at?->toIso8601String(),
                'travel_date' => $i->travel_date?->toDateString(),
                'party_size' => (int) $i->people_adults + (int) $i->people_children,
            ])->all(),
        ];
    }
}
