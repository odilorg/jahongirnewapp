<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Deterministic pre-filter — runs BEFORE the AI classifier to cheaply remove
 * obvious non-leads (saves an AI call). Pure, testable. Returns a junk subtype
 * to exclude on, or null to pass through to AI.
 *
 *   saved contact (a number WE saved = driver/guide/supplier/personal) -> 'supplier'
 *   B2B/marketing/spam lexicon hit                                     -> 'b2b'
 *   otherwise                                                          -> null (AI decides)
 *
 * The returned subtype is one of the auto-dismiss-eligible junk subtypes, so a
 * pre-filter hit is a deterministic not_lead. Conservative by design: only the
 * clearest non-traveler signals; everything else goes to the AI classifier.
 */
class WaLeadPrefilter
{
    /** @return string|null junk subtype to exclude on, or null to send to AI */
    public function excludeReason(string $message, bool $savedContact): ?string
    {
        if ($savedContact) {
            return 'supplier';
        }
        $low = mb_strtolower($message);
        foreach ((array) config('wa_leads.b2b_lexicon', []) as $kw) {
            if ($kw !== '' && str_contains($low, mb_strtolower((string) $kw))) {
                return 'b2b';
            }
        }
        return null;
    }
}
