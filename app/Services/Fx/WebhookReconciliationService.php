<?php

namespace App\Services\Fx;

use Illuminate\Support\Facades\Log;

/**
 * Handles incoming Beds24 payment webhooks when FX_WEBHOOK_RECONCILIATION is enabled.
 *
 * Protocol:
 *   1. Parse the [ref:UUID] from the payment description.
 *   2. Look up the Beds24PaymentSync row by local_reference.
 *   3. If found → mark Confirmed (idempotent). No new CashTransaction is created.
 *   4. If not found → treat as external/legacy payment (fall through to existing handler).
 *
 * @see Beds24WebhookController  — caller of this service
 */
class WebhookReconciliationService
{
    // Regex to extract the UUID from descriptions like "[ref:550e8400-e29b-41d4-a716-446655440000]"
    private const REF_PATTERN = '/\[ref:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]/i';

    public function __construct(
        private readonly Beds24PaymentSyncService $syncService,
    ) {}

    /**
     * Attempt to reconcile an incoming Beds24 payment webhook.
     *
     * @param  string  $description   The payment description field from Beds24 payload
     * @param  array   $rawPayload    Full webhook payload for audit storage
     *
     * @return bool  True if reconciled (bot-originated), false if external/legacy
     */
    public function reconcile(string $description, array $rawPayload): bool
    {
        $reference = $this->extractReference($description);

        if (! $reference) {
            return false; // No [ref:UUID] — not a bot-originated payment
        }

        $sync = $this->syncService->findByReference($reference);

        if (! $sync) {
            // Reference present but unknown — could be from a different environment
            Log::warning('WebhookReconciliation: unknown ref in webhook', [
                'ref'         => $reference,
                'description' => $description,
            ]);
            return false;
        }

        $this->syncService->markConfirmed($sync, $rawPayload);

        Log::info("WebhookReconciliation: confirmed sync #{$sync->id} for booking {$sync->beds24_booking_id}", [
            'ref' => $reference,
        ]);

        return true; // Caller must NOT create a duplicate CashTransaction
    }

    private function extractReference(string $description): ?string
    {
        if (preg_match(self::REF_PATTERN, $description, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
