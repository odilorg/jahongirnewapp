<?php

declare(strict_types=1);

namespace App\Exceptions\Fx;

/**
 * Thrown when the latest persisted `daily_exchange_rates` row is older
 * than `fx.stale_after_hours` (default 4h) at the moment a payment
 * session is being prepared.
 *
 * Background (2026-05-08 deep review tracked follow-up #1):
 *   The morning cron `fx:push-payment-options` runs at 07:00 Tashkent
 *   and writes a fresh row to `daily_exchange_rates` from the Central
 *   Bank of Uzbekistan API (with two free fallbacks). If that cron
 *   fails (network blip, API down, deploy-in-flight), the previous
 *   day's row continues to power cashier-bot sessions silently —
 *   `FxSyncService:88-106` falls back to "any latest row" with only a
 *   `Log::warning`. Rate goes silently stale, cashier records payments
 *   at yesterday's UZS/USD rate, drawer balance drifts.
 *
 *   This exception is the consumer-side gate that closes that hole:
 *   thrown at session preparation time so no payment can be recorded
 *   against a stale rate. The bot surfaces a clean operator message
 *   and refuses to continue. Filament admin's mixed-currency surface
 *   gets the same refusal because both paths flow through
 *   `BotPaymentService::preparePayment`.
 *
 * Caught at the controller boundary; logged at WARNING (not ERROR —
 * staleness is the *expected* signal after a cron failure, ERROR is
 * reserved for unexpected app errors).
 */
final class StaleFxRateException extends \RuntimeException {}
