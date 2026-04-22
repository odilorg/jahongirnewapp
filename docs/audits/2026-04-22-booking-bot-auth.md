# Booking Bot — Phone-Auth Audit

**Date:** 2026-04-22
**Scope:** `@j_booking_hotel_bot` auth pipeline end-to-end
**Auditor:** Phase 10.8
**Surface reviewed:**
- `routes/api.php:65-78` — two webhook routes both slugged `booking`:
  - `/telegram/bot/webhook` → `TelegramWebhookController::handle`
  - `/booking/bot/webhook` → `BookingWebhookController::handle`
  Both carry `verify.telegram.webhook:booking` and both dispatch `ProcessBookingMessage`.
  One is likely dead code — recommend triage in a follow-up (not this phase).
- `app/Http/Middleware/VerifyTelegramWebhook.php`
- `app/Http/Controllers/BookingWebhookController.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `app/Jobs/ProcessBookingMessage.php`
- `app/Services/StaffAuthorizationService.php`
- `app/Actions/BookingBot/Handlers/HandlePhoneContactAction.php`
- `app/Models/User` (auth helper methods)
- `app/Services/TelegramBotService.php` (setWebhook)
- `database/migrations/2025_10_15_041916_add_separate_telegram_fields_for_each_bot_to_users_table.php`

---

## Findings

| ID | Severity | Status | Summary |
|----|----------|--------|---------|
| B1 | Low | Documented | Unauthorized updates enter job queue before auth check |
| B2 | Medium | Documented — defer | `linkPhoneNumber` uses fuzzy `LIKE %last9digits` |
| B3 | Low | Documented | No rate limit on phone-share attempts |
| B4 | — | OK | `booking_bot_enabled=false` is honored |
| B5 | **High** | **Fixed inline (Phase 10.8)** | Webhook secret defined in config, not enforced |
| B6 | — | OK | Audit-trail logs are PII-sanitized (Phase 10.7) |
| B7 | — | OK | Disabling a user takes effect next request |
| B8 | Medium | **Fixed inline (Phase 10.8)** | `telegram_booking_user_id` index is non-unique |
| B9 | — | OK | Callback queries use same `verifyTelegramUser` path |
| B10 | — | OK | PII redaction active after 10.7 |

---

## B5 — Webhook secret not enforced (HIGH)

### What

`routes/api.php:65-78` defines both `/telegram/bot/webhook` and `/booking/bot/webhook`, each protected by `verify.telegram.webhook:booking`. Both resolve the same config key `services.telegram_booking_bot.secret_token`. The middleware (`VerifyTelegramWebhook::handle`) looks up that key; if empty, migration-mode fallback passes the request through UNENFORCED with a once-per-process warning log. The B5 code fix below patches `TelegramBotService::setWebhook()`, which is the single `setWebhook` registration path used by both routes — fixing one call site covers both.

Production state on Jahongir VPS (2026-04-22):
- `TELEGRAM_BOOKING_SECRET_TOKEN` was not set in `.env`.
- `services.telegram_booking_bot.secret_token` resolved to `null`.
- Every webhook request was accepted without validation of Telegram's `X-Telegram-Bot-Api-Secret-Token` header.

### Impact

Anyone who learned the public webhook URL could forge Telegram updates:
- Inject a `message` with `from.id` matching an authorized staff Telegram ID.
- Enqueue any command (create/cancel/modify booking) as that staff user.
- PII in the forged update would land in logs.

Probability of exploit: low (URL not public). Severity if exploited: high (create/cancel real Beds24 bookings). Overall rating: **HIGH** — fix in-PR.

### Root causes (two)

1. **Code:** `TelegramBotService::setWebhook(string $url): array` never forwards `config('services.telegram_booking_bot.secret_token')` to the transport, even though `TelegramTransport::setWebhook()` accepts `?string $secretToken`. So even if the env var were set, registration with Telegram would not tell Telegram to include the header.
2. **Ops:** `TELEGRAM_BOOKING_SECRET_TOKEN` was never provisioned on VPS.

### Fix applied in this PR (code)

- `TelegramBotService::setWebhook()` now reads the configured secret and forwards it to `transport->setWebhook()`. If the secret is empty, it logs a warning and calls setWebhook without a token (keeps legacy migration-mode behavior while we roll out).
- Added unit test covering both branches (secret present → forwarded; secret empty → warning + no forward).

### Fix still required (ops — post-merge runbook)

```bash
# 1) On VPS: generate secret and set env var
ssh jahongir
cd /var/www/jahongirnewapp
SECRET=$(openssl rand -hex 32)     # 64 hex chars, matches Telegram's allowed charset
echo "TELEGRAM_BOOKING_SECRET_TOKEN=${SECRET}" >> .env
sudo -u www-data php artisan config:clear

# 2) Register with Telegram (uses the new forwarding code)
curl -X POST https://<APP_URL>/telegram/bot/set-webhook \
  -H "Authorization: Bearer <ADMIN_SANCTUM_TOKEN>"

# 3) Verify Telegram stored the token
curl -X GET https://<APP_URL>/telegram/bot/webhook-info \
  -H "Authorization: Bearer <ADMIN_SANCTUM_TOKEN>"
# result.has_custom_certificate / allowed_updates / url

# 4) Confirm enforcement now blocks unsigned requests
curl -X POST https://<APP_URL>/booking/bot/webhook -H 'Content-Type: application/json' -d '{}'
# expected: 403 Forbidden
```

After ops step 2, the middleware's migration-mode warning stops firing and all requests without a matching `X-Telegram-Bot-Api-Secret-Token` return 403.

---

## B8 — Non-unique index on `telegram_booking_user_id` (MEDIUM)

### What

Migration `2025_10_15_041916_add_separate_telegram_fields_for_each_bot_to_users_table.php:26` creates a non-unique index (`$table->index(...)`). Verified on production: `SHOW INDEX ... WHERE Column_name='telegram_booking_user_id'` → `Non_unique: 1`.

### Impact

No DB-level guarantee that a given Telegram user ID maps to exactly one User. Today, `StaffAuthorizationService::linkPhoneNumber` always `->update()`s an existing row (by phone match), so duplicates would only arise from a bug or a manual admin SQL mistake.

Current production data (2026-04-22): `SELECT telegram_booking_user_id, COUNT(*) c FROM users WHERE telegram_booking_user_id IS NOT NULL GROUP BY telegram_booking_user_id HAVING c > 1` → empty. No duplicates in the wild.

### Fix applied in this PR

- New migration `add_unique_index_on_telegram_booking_user_id_in_users_table` drops `idx_telegram_booking_user_id` and recreates it as unique. Safe because no existing duplicates.
- Migration is reversible: `down()` restores the non-unique index.

This is defense-in-depth. The scenario it protects against: a future bug or operator mistake that would silently clobber the Telegram → User mapping.

---

## B1 — Unauthorized updates enter job queue (LOW)

Webhook controller always dispatches `ProcessBookingMessage`. Auth check happens inside the job. An attacker with a valid secret token (post-B5 fix) but an unknown Telegram ID still burns a queue slot + one LLM call's worth of no-op logging.

**Recommendation (defer):** early reject in `BookingWebhookController` when `from.id` isn't in the allowlist. Defer — real-world impact is negligible once B5 is fixed because only Telegram can send valid requests.

---

## B2 — Fuzzy phone match in `linkPhoneNumber` (MEDIUM — defer)

`StaffAuthorizationService::linkPhoneNumber` matches on `phone_number LIKE %last9digits` in addition to exact match. Two authorized staff whose phone numbers share the trailing 9 digits (international + country code difference) would be indistinguishable; whichever row the ORM returns first wins.

**Recommendation (defer):** tighten to `phone_number = $digits OR phone_number = '+'.$digits`. Requires a data audit first — the fuzzy match may be compensating for inconsistent historical data entry. Schedule as a separate phase.

---

## B3 — No rate limit on phone-share attempts (LOW)

An attacker who guesses authorized phone numbers could brute-force via Telegram's contact-share mechanism, limited only by Telegram's own flood control.

**Recommendation (defer):** route-level throttle on `contact` messages. Low priority — the attacker needs to be authorized to send *any* Telegram message to the bot, which requires the bot's username.

---

## B4 — `booking_bot_enabled=false` is honored (OK)

`StaffAuthorizationService::verifyTelegramUser:34-40` checks the flag and returns null with an info log. Confirmed by reading the code path.

## B6 — Audit-trail logs are PII-sanitized (OK post-10.7)

After Phase 10.7 (deployed 2026-04-22, commit `fc51f3ac`), every Log::info/warn/error call in the booking-bot pipeline flows through `LogSanitizer::context()` or explicit `commandPreview`. Raw phone/email/surname cannot reach INFO logs by default. Escape hatch: `LOG_BOOKING_BOT_DEBUG_PAYLOADS=true`.

## B7 — Disabling a user takes effect next request (OK)

Every job re-reads the User row through `findByBookingBotTelegramId` and re-checks `booking_bot_enabled`. No cached auth state.

## B9 — Callback query auth (OK)

`ProcessBookingMessage:41-44` dispatches callback queries to `HandleCallbackQueryAction`. Before that, `verifyTelegramUser` is called with the full update, which correctly pulls `from` from either `message.from` or `callback_query.from`. Callback-query button taps go through the same gate as text messages.

## B10 — PII in logs (OK post-10.7)

Same as B6.

---

## Closing

Inline fixes in this PR resolve B5 (code hole) and B8 (DB index). Ops still owes step 2+3 of the B5 runbook to set the secret token and re-register the webhook — until that happens, the middleware's migration-mode warning will continue to fire once per PHP process on every deploy.

Everything else is documented for later. None of the deferred items represent a current active exposure.
