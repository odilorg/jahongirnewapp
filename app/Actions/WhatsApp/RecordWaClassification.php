<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Models\WaLeadCandidate;
use App\Services\WhatsApp\WaLeadDecision;

/**
 * Record a classifier verdict onto a candidate and route it. The two terminal
 * actions are SEPARATELY gated and BOTH default OFF:
 *   - auto-dismiss (junk) only if config('wa_leads.auto_dismiss_enabled')
 *   - auto-create only if config('wa_leads.auto_create_enabled') — and the
 *     create itself is Phase 2d (not built here), so even with the gate on this
 *     routes to review for now.
 * With both gates off (the default), EVERYTHING goes to review — nothing is
 * dismissed, nothing creates a booking_inquiry. This action never touches
 * booking_inquiries.
 */
class RecordWaClassification
{
    public function __construct(private WaLeadDecision $decision)
    {
    }

    /**
     * @param array<string, mixed> $result classifier JSON
     * @return array{decision: string, status: string}
     */
    public function record(WaLeadCandidate $candidate, array $result): array
    {
        $valid = [WaLeadCandidate::CLASS_GENUINE, WaLeadCandidate::CLASS_NOT_LEAD, WaLeadCandidate::CLASS_UNCERTAIN];
        $class = in_array($result['classification'] ?? null, $valid, true)
            ? $result['classification']
            : WaLeadCandidate::CLASS_UNCERTAIN;          // malformed -> uncertain -> review
        $conf = is_numeric($result['confidence'] ?? null) ? (float) $result['confidence'] : 0.0;
        $subtype = $result['not_lead_subtype'] ?? null;

        $decision = $this->decision->decide($class, $conf, $subtype);

        $fields = [
            'classification'      => $class,
            'not_lead_subtype'    => $subtype,
            'confidence'          => $conf,
            'reason'              => is_string($result['reason'] ?? null) ? mb_substr($result['reason'], 0, 500) : null,
            'detected_tour'       => is_string($result['detected_tour'] ?? null) ? mb_substr($result['detected_tour'], 0, 191) : null,
            'detected_date'       => $this->validDate($result['detected_date'] ?? null),
            'detected_party_size' => is_numeric($result['party_size'] ?? null) ? (int) $result['party_size'] : null,
            'language'            => is_string($result['language'] ?? null) ? mb_substr($result['language'], 0, 8) : null,
            'decision'            => $decision,
            'needs_review'        => $decision === WaLeadDecision::REVIEW,
            'classified_at'       => now(),
        ];

        if ($decision === WaLeadDecision::AUTO_DISMISS && (bool) config('wa_leads.auto_dismiss_enabled')) {
            $fields['status']           = WaLeadCandidate::STATUS_DISMISSED;
            $fields['dismissed_reason'] = (string) $subtype;
            $fields['decided_by']       = 'classifier';
            $fields['decided_at']       = now();
        } else {
            // would_auto_create (2d not built) + would_review + gated-off dismiss
            // all land in the operator review queue.
            $fields['status'] = WaLeadCandidate::STATUS_REVIEW;
        }

        $candidate->forceFill($fields)->save();

        return ['decision' => $decision, 'status' => $candidate->status];
    }

    private function validDate(mixed $v): ?string
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
