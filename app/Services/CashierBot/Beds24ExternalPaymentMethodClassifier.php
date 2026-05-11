<?php

declare(strict_types=1);

namespace App\Services\CashierBot;

/**
 * Single source of truth for the question:
 * "Is this Beds24-external payment_method string a CASH method?"
 *
 * Used by `Beds24WebhookController::createExternalBookkeepingRow`
 * to decide whether a row is eligible for drawer-truth (guard #1
 * of the five-guard chain — see Phase 1 plan, 2026-05-11).
 *
 * # Normalisation
 *
 * Beds24 admin lets the operator type the method as freeform text
 * and we've observed at least three real strings in production
 * (30-day audit, 2026-05-11):
 *
 *   karta → Russian "card"   → NOT cash
 *   naqd  → Uzbek "cash"     → IS cash
 *   cash  → English          → IS cash
 *
 * The allow-list is config-driven (`cashier.beds24_external_cash_methods`)
 * so operators can add new variants (e.g. "нал", "наличные") without
 * a code redeploy. Matching is case-folded + whitespace-trimmed.
 *
 * # Empty / null
 *
 * Empty or null `payment_method` is treated as NON-cash for the
 * webhook path. Rationale: Beds24 didn't tell us what method was
 * used, so we should not auto-trust it as cash. The manager can
 * still flip the row manually via the Filament reconciliation page
 * once they confirm with the front desk.
 *
 * # Why a service, not a static helper
 *
 * Per project convention this rule will likely grow (e.g. when
 * Beds24 introduces a "qr_code" method, or when an admin types
 * "naqd pulі" with a typo and we want fuzzy matching). Keeping it
 * behind a tiny interface lets us swap implementations or add
 * caching without touching every caller.
 */
final class Beds24ExternalPaymentMethodClassifier
{
    /**
     * Normalised cash allow-list, lazily-resolved per instance.
     * Cached so the config lookup + array_map happens once per request
     * even if `isCash()` is invoked many times (e.g. across a batch
     * of webhook payments).
     *
     * @var array<int, string>|null
     */
    private ?array $cachedAllowList = null;

    /**
     * @return bool True when $rawMethod (after case-fold + trim)
     *              matches a configured cash variant.
     */
    public function isCash(?string $rawMethod): bool
    {
        $normalized = $this->normalize($rawMethod);

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->allowList(), true);
    }

    /**
     * Normalised, lowercased form of the raw Beds24 method string.
     * Empty string when input is null/whitespace-only.
     */
    public function normalize(?string $rawMethod): string
    {
        return strtolower(trim((string) $rawMethod));
    }

    /**
     * @return array<int, string>
     */
    private function allowList(): array
    {
        if ($this->cachedAllowList !== null) {
            return $this->cachedAllowList;
        }

        $raw = (array) config('cashier.beds24_external_cash_methods', ['cash', 'naqd']);

        $this->cachedAllowList = array_values(array_unique(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $raw,
        )));

        return $this->cachedAllowList;
    }
}
