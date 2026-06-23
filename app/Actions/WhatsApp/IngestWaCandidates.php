<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;

/**
 * Dedup + queue WhatsApp prospect candidates for OPERATOR REVIEW. It never
 * creates a booking_inquiry (no reliable auto-qualifier for WhatsApp) — it only
 * upserts `pending` rows into wa_lead_candidates. Decision tree per candidate
 * (biased to skip): invalid phone -> skip; phone already a CRM inquiry (any
 * status) -> skip; phone already a candidate (any status, incl. dismissed) ->
 * skip (idempotent + respects prior dismissals); else -> queue pending.
 */
class IngestWaCandidates
{
    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, int>
     */
    public function ingest(array $candidates, bool $dryRun): array
    {
        $counts = [];
        foreach ($candidates as $c) {
            $phone = BookingInquiry::normalizePhone((string) ($c['phone'] ?? ''));
            if ($phone === null || strlen($phone) < 8) {   // too short to be a real number
                $counts['skip:invalid_phone'] = ($counts['skip:invalid_phone'] ?? 0) + 1;
                continue;
            }
            if ($this->phoneHasInquiry($phone)) {
                $counts['skip:existing_inquiry'] = ($counts['skip:existing_inquiry'] ?? 0) + 1;
                continue;
            }
            if (WaLeadCandidate::where('phone', $phone)->exists()) {
                $counts['skip:already_candidate'] = ($counts['skip:already_candidate'] ?? 0) + 1;
                continue;
            }

            $key = $dryRun ? 'would_queue' : 'queued';
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            if (! $dryRun) {
                WaLeadCandidate::create([
                    'phone'          => $phone,
                    'first_inbound'  => mb_substr((string) ($c['first_inbound'] ?? ''), 0, 1000),
                    'last_inbound_at' => $c['last_inbound_at'] ?? null,
                    'inbound_count'  => (int) ($c['inbound'] ?? 0),
                    'outbound_count' => (int) ($c['outbound'] ?? 0),
                    'status'         => WaLeadCandidate::STATUS_PENDING,
                ]);
            }
        }
        return $counts;
    }

    /** Any booking_inquiry (any status) whose normalized phone matches. */
    private function phoneHasInquiry(string $normalizedPhone): bool
    {
        return BookingInquiry::query()
            ->whereRaw("REGEXP_REPLACE(COALESCE(customer_phone, ''), '[^0-9]', '') = ?", [$normalizedPhone])
            ->exists();
    }
}
