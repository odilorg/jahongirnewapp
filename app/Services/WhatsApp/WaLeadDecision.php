<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Pure decision rule for a classified WhatsApp candidate (no IO, fully testable).
 * The single source of truth for what the classifier's verdict WOULD trigger:
 *
 *   genuine_tour_inquiry, conf >= autocreate_min     -> would_auto_create
 *   not_lead, conf >= autodismiss_min, subtype JUNK  -> would_auto_dismiss
 *   everything else                                  -> would_review
 *
 * CRITICAL: only spam|b2b|supplier are auto-dismiss-eligible. accommodation,
 * logistics, personal, other, uncertain, malformed, or low confidence ALWAYS
 * go to review — real (non-tour) people are never silently dropped.
 */
class WaLeadDecision
{
    public const AUTO_CREATE  = 'would_auto_create';
    public const AUTO_DISMISS = 'would_auto_dismiss';
    public const REVIEW       = 'would_review';

    public function decide(?string $classification, ?float $confidence, ?string $notLeadSubtype): string
    {
        $conf = (float) ($confidence ?? 0.0);

        if ($classification === 'genuine_tour_inquiry'
            && $conf >= (float) config('wa_leads.autocreate_min_conf', 0.85)) {
            return self::AUTO_CREATE;
        }

        if ($classification === 'not_lead'
            && $conf >= (float) config('wa_leads.autodismiss_min_conf', 0.90)
            && in_array($notLeadSubtype, (array) config('wa_leads.junk_subtypes', ['spam', 'b2b', 'supplier']), true)) {
            return self::AUTO_DISMISS;
        }

        return self::REVIEW;
    }
}
