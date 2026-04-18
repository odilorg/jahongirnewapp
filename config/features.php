<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FX Bot Payment V2
    |--------------------------------------------------------------------------
    | When true, the cashier bot uses the new FX presentation flow:
    |   - Reads frozen PaymentPresentation from booking_fx_syncs
    |   - Records UZS/EUR/RUB/USD amounts
    |   - Applies 0.5% tolerance rule
    |   - Pushes payment to Beds24 after recording
    |
    | When false, the legacy payment flow is used (no FX presentation).
    */
    'fx_bot_payment_v2' => (bool) env('FX_BOT_PAYMENT_V2', false),

    /*
    |--------------------------------------------------------------------------
    | Beds24 Auto Push Payment
    |--------------------------------------------------------------------------
    | When true, after recording a local CashTransaction, dispatch
    | Beds24PaymentSyncJob to push the payment into Beds24.
    | Depends on fx_bot_payment_v2 being true.
    */
    'beds24_auto_push_payment' => (bool) env('BEDS24_AUTO_PUSH_PAYMENT', false),

    /*
    |--------------------------------------------------------------------------
    | FX Webhook Reconciliation
    |--------------------------------------------------------------------------
    | When true, Beds24 webhook payment events are routed through
    | WebhookReconciliationService:
    |   - Bot-originated payments are confirmed (not duplicated)
    |   - External payments are recorded as beds24_external
    |
    | When false, the legacy createPaymentTransaction() path runs.
    */
    'fx_webhook_reconciliation' => (bool) env('FX_WEBHOOK_RECONCILIATION', false),

    /*
    |--------------------------------------------------------------------------
    | Ledger Shadow Mode
    |--------------------------------------------------------------------------
    | L-006 onward: when a shadow flag is TRUE, the corresponding source
    | also writes to ledger_entries alongside its legacy table. Legacy
    | behaviour is unchanged. Reads still come from legacy. Shadow writes
    | are observer-only — a ledger insert failure NEVER fails the legacy
    | operation.
    |
    | Flip to true in staging first. Leave off in production until the
    | L-006.5 parity report shows zero drift for 7 consecutive days.
    */
    'ledger' => [
        'shadow' => [
            'beds24'   => (bool) env('LEDGER_SHADOW_BEDS24',   false),
            'octo'     => (bool) env('LEDGER_SHADOW_OCTO',     false),
            'cashier'  => (bool) env('LEDGER_SHADOW_CASHIER',  false),
        ],

        /*
        |------------------------------------------------------------
        | Runtime Write Firewall (L-018)
        |------------------------------------------------------------
        | Controls what happens when LedgerEntry::create() fires
        | OUTSIDE an active LedgerWriteContext binding (i.e. NOT from
        | inside App\Actions\Ledger\RecordLedgerEntry::execute or a
        | future sanctioned bulk writer).
        |
        |  'off'      (default) — firewall does nothing; unchanged
        |                          behaviour.
        |  'warn'                 — the write proceeds but a structured
        |                          log line is emitted with a stack
        |                          trace so discipline breaches are
        |                          visible without breaking production.
        |  'enforce'              — throw LedgerWriteForbiddenException;
        |                          the row is NOT written. Ship-ready
        |                          once Phase C completes and every
        |                          writer uses the action.
        */
        'firewall' => [
            'mode' => env('LEDGER_FIREWALL_MODE', 'off'),
        ],
    ],
];
