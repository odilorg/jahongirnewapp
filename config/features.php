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
];
