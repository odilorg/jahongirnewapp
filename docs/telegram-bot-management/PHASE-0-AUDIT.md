# Phase 0: Telegram Bot Audit & Inventory

> **Branch:** `chore/telegram-audit-inventory`
> **Date:** 2026-03-18
> **Scope:** Read-only audit. Zero production behaviour changes.

---

## 1. Reproducible Search Terms

Every finding below can be verified by running these from the repo root:

```bash
# 1. All env() calls referencing Telegram or bot tokens
rg 'env\(['\''"].*(?:TELEGRAM|BOT_TOKEN|OWNER_ALERT|CASHIER_BOT|HOUSEKEEPING|KITCHEN_BOT)' --glob '*.php' -n

# 2. All config() calls for telegram/bot services
rg 'config\(['\''"]services\.(telegram|owner_alert|driver_guide|cashier|housekeeping|kitchen|pos)' --glob '*.php' -n

# 3. All HTTP calls to Telegram API
rg 'api\.telegram\.org' --glob '*.php' -n

# 4. All env() calls with fallback inside non-config files (violation of Laravel convention)
rg 'env\(' --glob '*.php' --glob '!config/*.php' --glob '!bootstrap/*.php' -n | grep -i 'telegram\|bot'

# 5. All variable assignments containing bot.*token
rg '\$.*bot.*[Tt]oken' --glob '*.php' -n

# 6. Webhook routes
rg 'telegram.*webhook|webhook.*telegram' routes/api.php -n -i

# 7. Token passed into job constructors (serialised to queue)
rg 'botToken' app/Jobs/ -n
```

---

## 2. Bot Inventory Table

| # | Logical Bot | Env Var(s) | Config Path(s) | Bot Purpose |
|---|-------------|-----------|-----------------|-------------|
| 1 | **Main / Booking** | `TELEGRAM_BOT_TOKEN` | `services.telegram.bot_token`, `services.telegram_bot.token`, `services.telegram_booking_bot.token` | General bot, booking conversations, scheduled messages |
| 2 | **Driver & Guide** | `TELEGRAM_BOT_TOKEN_DRIVER_GUIDE` | `services.telegram_bot.bot_token`, `services.driver_guide_bot.token` | Tour reminders to drivers/guides, signup flow, GYG notifications |
| 3 | **POS** | `TELEGRAM_POS_BOT_TOKEN` | `services.telegram_pos_bot.token` | Point-of-sale terminal via Telegram |
| 4 | **Owner Alert** | `OWNER_ALERT_BOT_TOKEN` | `services.owner_alert_bot.token` | Booking alerts, daily summaries, cash reports, health checks |
| 5 | **Cashier** | `CASHIER_BOT_TOKEN` | `services.cashier_bot.token` | Cashier shift management, payment/expense logging |
| 6 | **Housekeeping** | `HOUSEKEEPING_BOT_TOKEN` | `services.housekeeping_bot.token` | Room cleaning tasks, issue reporting, photo uploads |
| 7 | **Kitchen** | `KITCHEN_BOT_TOKEN` | `services.kitchen_bot.token` | Kitchen operations, meal count reporting |

### Webhook Secrets Inventory

| Bot | Secret Env Var | Config Path | Middleware | Status |
|-----|---------------|-------------|------------|--------|
| POS | `TELEGRAM_POS_SECRET_TOKEN` | `services.telegram_pos_bot.secret_token` | `ValidateTelegramRequest` | **BROKEN** — validation commented out (line 39) |
| Booking | `TELEGRAM_BOOKING_SECRET_TOKEN` | `services.telegram_booking_bot.secret_token` | None | **UNUSED** — configured but never validated |
| Cashier | `CASHIER_BOT_WEBHOOK_SECRET` | `services.cashier_bot.webhook_secret` | `VerifyTelegramWebhook` | **OK** — fail-closed, `hash_equals()` |
| Driver/Guide | `DRIVER_GUIDE_WEBHOOK_SECRET` | `services.driver_guide_bot.webhook_secret` | None | **UNUSED** — configured but never validated |
| Owner Alert | — | — | None | **MISSING** — no secret configured at all |
| Housekeeping | — | — | None | **MISSING** — no secret configured at all |
| Kitchen | — | — | None | **MISSING** — no secret configured at all |

### Associated Chat IDs / Metadata

| Env Var | Config Path | Purpose |
|---------|-------------|---------|
| `OWNER_TELEGRAM_ID` | `services.owner_alert_bot.owner_chat_id` | Owner's personal chat ID for alerts |
| `TELEGRAM_OWNER_CHAT_ID` | `services.driver_guide_bot.owner_chat_id` | Owner chat ID (driver/guide context) — default `38738713` |
| `HOUSEKEEPING_MGMT_GROUP_ID` | `services.housekeeping_bot.mgmt_group_id` | Housekeeping management group |
| `TELEGRAM_POS_WEBHOOK_URL` | `services.telegram_pos_bot.webhook_url` | POS bot webhook URL |
| `TELEGRAM_BOOKING_WEBHOOK_URL` | `services.telegram_booking_bot.webhook_url` | Booking bot webhook URL |
| `TELEGRAM_POS_SESSION_TIMEOUT` | `services.telegram_pos_bot.session_timeout` | POS session timeout (default 480 min) |
| `TELEGRAM_BOOKING_SESSION_TIMEOUT` | `services.telegram_booking_bot.session_timeout` | Booking session timeout (default 15 min) |

---

## 3. Consumer Inventory (Who Reads Tokens)

### Controllers

| File | Class | Token(s) Used | How Obtained | API Methods Called |
|------|-------|---------------|-------------|-------------------|
| `app/Http/Controllers/TelegramController.php` | TelegramController | Main | `config('services.telegram_bot.token')` in constructor | sendMessage, generic method dispatch |
| `app/Http/Controllers/TelegramPosController.php` | TelegramPosController | POS | `config('services.telegram_pos_bot.token')` in constructor | sendMessage, answerCallbackQuery |
| `app/Http/Controllers/CashierBotController.php` | CashierBotController | Cashier + Owner Alert (fallback) | `config('services.cashier_bot.token', config('services.owner_alert_bot.token'))` | sendMessage, answerCallbackQuery, sendPhoto (owner bot) |
| `app/Http/Controllers/OwnerBotController.php` | OwnerBotController | Owner Alert + Cashier | `config(…, env('OWNER_ALERT_BOT_TOKEN'))` **env() outside config** | sendMessage (both bots) |
| `app/Http/Controllers/HousekeepingBotController.php` | HousekeepingBotController | Housekeeping | `config('services.housekeeping_bot.token', '')` | sendMessage, answerCallbackQuery, getFile, file download |
| `app/Http/Controllers/KitchenBotController.php` | KitchenBotController | Kitchen | `config('services.kitchen_bot.token', '')` | sendMessage, editMessageText, answerCallbackQuery |
| `app/Http/Controllers/TelegramDriverGuideSignUpController.php` | TelegramDriverGuideSignUpController | Driver/Guide | `config('services.driver_guide_bot.token', '')` | sendMessage via Guzzle Client |
| `app/Http/Controllers/Beds24WebhookController.php` | Beds24WebhookController | Housekeeping | `config('services.housekeeping_bot.token', '')` at line 604 | sendMessage |

### Services

| File | Class | Token(s) Used | How Obtained | Notes |
|------|-------|---------------|-------------|-------|
| `app/Services/TelegramBotService.php` | TelegramBotService | Main | `config(…, env("TELEGRAM_BOT_TOKEN"))` **env() outside config** | Generic wrapper: sendMessage, setWebhook, getWebhookInfo, deleteWebhook, answerCallbackQuery, editMessageText |
| `app/Services/OwnerAlertService.php` | OwnerAlertService | Owner Alert | `config(…, env('OWNER_ALERT_BOT_TOKEN', ''))` **env() outside config** | Highest usage: 10+ alert methods, dispatches `SendTelegramNotificationJob` with raw token |
| `app/Services/GygNotifier.php` | GygNotifier | Driver/Guide | `config('services.driver_guide_bot.token', '')` | sendMessage for GYG booking notifications |
| `app/Services/Beds24BookingService.php` | Beds24BookingService | Owner Alert | `config('services.owner_alert_bot.token')` | Direct sendMessage for booking sync alerts |

### Jobs

| File | Class | Token Source | Critical Issue |
|------|-------|-------------|----------------|
| `app/Jobs/SendTelegramNotificationJob.php` | SendTelegramNotificationJob | **Constructor parameter** `$botToken` | **TOKEN SERIALIZED TO QUEUE** — plaintext token stored in `jobs` table / Redis queue payload |
| `app/Jobs/SendTelegramMessageJob.php` | SendTelegramMessageJob | `config('services.telegram_bot.token')` at runtime | OK — reads at execution time, not serialized |

### Console Commands

| File | Class | Token(s) Used | How Obtained |
|------|-------|---------------|-------------|
| `app/Console/Commands/QueueHealthCheck.php` | QueueHealthCheck | Owner Alert | `config(…, env('OWNER_ALERT_BOT_TOKEN'))` **env() outside config** |
| `app/Console/Commands/TourSendReminders.php` | TourSendReminders | Owner Alert + Driver/Guide | `config(…, env(…))` for both **env() outside config** |
| `app/Console/Commands/RefreshBeds24Token.php` | RefreshBeds24Token | Owner Alert | `config('services.owner_alert_bot.token')` |
| `app/Console/Commands/SetTelegramPosWebhook.php` | SetTelegramPosWebhook | POS | `config('services.telegram_pos_bot.token')` |
| `app/Console/Commands/AssertProductionConfig.php` | AssertProductionConfig | Cashier + Owner (validation only) | `config(…)` — checks non-empty, does not use token |

### Observers

| File | Class | Token(s) Used | How Obtained |
|------|-------|---------------|-------------|
| `app/Observers/BookingObserver.php` | BookingObserver | Driver/Guide | `config(…, env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'))` **env() outside config** — also creates Guzzle Client with `base_uri` |

### Middleware

| File | Class | Token Reference | Purpose |
|------|-------|----------------|---------|
| `app/Http/Middleware/VerifyTelegramWebhook.php` | VerifyTelegramWebhook | `config('services.cashier_bot.webhook_secret')` | Validates `X-Telegram-Bot-Api-Secret-Token` header. **Well implemented:** fail-closed, `hash_equals()`. |
| `app/Http/Middleware/ValidateTelegramRequest.php` | ValidateTelegramRequest | `config('services.telegram_pos_bot.token')` | **Broken:** only checks token exists. Secret validation commented out (line 37-41). |

---

## 4. Webhook Route Map

| Route | Method | Controller | Middleware | Secret Validated? |
|-------|--------|-----------|------------|-------------------|
| `/api/telegram/webhook` | POST | TelegramController::handleWebhook | **None** | NO |
| `/api/telegram/driver_guide_signup` | POST | TelegramDriverGuideSignUpController::handleWebhook | **None** | NO (secret in config but unused) |
| `/api/telegram/bot/webhook` | POST | TelegramWebhookController::handle | **None** | NO |
| `/api/booking/bot/webhook` | POST | BookingWebhookController::handle | **None** | NO |
| `/api/telegram/pos/webhook` | POST | TelegramPosController::handleWebhook | ValidateTelegramRequest | **BROKEN** (commented out) |
| `/api/telegram/cashier/webhook` | POST | CashierBotController::handleWebhook | **verify.telegram.webhook** | **YES** |
| `/api/telegram/owner/webhook` | POST | OwnerBotController::handleWebhook | **None** | NO |
| `/api/telegram/housekeeping/webhook` | POST | HousekeepingBotController::handleWebhook | **None** | NO |
| `/api/telegram/kitchen/webhook` | POST | KitchenBotController::handleWebhook | **None** | NO |

**Result: 1 of 9 webhook endpoints is properly authenticated.**

---

## 5. Legacy Config Path → Target Bot Slug Map

| Legacy Config Path | Env Var | Target Slug | Notes |
|-------------------|---------|-------------|-------|
| `services.telegram.bot_token` | `TELEGRAM_BOT_TOKEN` | `main` | Alias used by TelegramBotService |
| `services.telegram_bot.token` | `TELEGRAM_BOT_TOKEN` | `main` | Same token, different config path |
| `services.telegram_booking_bot.token` | `TELEGRAM_BOT_TOKEN` | `main` | Same token reused for booking |
| `services.telegram_bot.bot_token` | `TELEGRAM_BOT_TOKEN_DRIVER_GUIDE` | `driver_guide` | Confusingly nested under `telegram_bot` |
| `services.driver_guide_bot.token` | `TELEGRAM_BOT_TOKEN_DRIVER_GUIDE` | `driver_guide` | Canonical path |
| `services.telegram_pos_bot.token` | `TELEGRAM_POS_BOT_TOKEN` | `pos` | — |
| `services.owner_alert_bot.token` | `OWNER_ALERT_BOT_TOKEN` | `owner_alert` | Most widely used bot |
| `services.cashier_bot.token` | `CASHIER_BOT_TOKEN` | `cashier` | Falls back to owner_alert if empty |
| `services.housekeeping_bot.token` | `HOUSEKEEPING_BOT_TOKEN` | `housekeeping` | — |
| `services.kitchen_bot.token` | `KITCHEN_BOT_TOKEN` | `kitchen` | — |

### Slug Consolidation

The `main` slug absorbs 3 config paths that all read `TELEGRAM_BOT_TOKEN`:
- `services.telegram.bot_token`
- `services.telegram_bot.token`
- `services.telegram_booking_bot.token`

After migration, all 3 consumers resolve via `telegram_bot('main')->token()`.

---

## 6. Architecture Proposal (Adjusted to This Codebase)

### 6.1 Models

```
App\Models\TelegramBot
  - slug (unique, indexed)
  - name, bot_username, description
  - status: BotStatus enum (active, disabled, revoked)
  - environment: BotEnvironment enum (production, staging, development)
  - metadata (json): chat_ids, webhook_url, session_timeout, etc.
  - last_used_at, last_error_at, last_error_code, last_error_summary
  - created_by, updated_by (FK → users)
  - timestamps, softDeletes

App\Models\TelegramBotSecret
  - telegram_bot_id (FK)
  - version (int, auto-increment per bot)
  - token_encrypted (text — Laravel Crypt)
  - webhook_secret_encrypted (text nullable — Laravel Crypt)
  - status: SecretStatus enum (active, pending, revoked)
  - activated_at, revoked_at
  - created_by (FK → users)
  - timestamps

App\Models\TelegramBotAccessLog
  - telegram_bot_id (FK nullable)
  - actor_user_id (FK nullable)
  - actor_type, actor_identifier (for system/job/command actors)
  - service_name (e.g. "App\Services\OwnerAlertService")
  - action: AccessAction enum (token_read, message_sent, webhook_set, webhook_received, error, token_rotated, token_revealed)
  - result: AccessResult enum (success, denied, not_found, error)
  - ip_address, user_agent, request_id
  - metadata (json)
  - created_at (no updated_at — append only)
```

### 6.2 Contracts (Interfaces)

```php
App\Contracts\Telegram\BotResolverInterface
  - resolve(string $slug): ResolvedTelegramBot
  - resolveOrFail(string $slug): ResolvedTelegramBot  // throws BotNotFoundException

App\Contracts\Telegram\BotSecretProviderInterface
  - getActiveToken(TelegramBot $bot): string           // throws BotSecretUnavailableException
  - getActiveWebhookSecret(TelegramBot $bot): ?string

App\Contracts\Telegram\BotAuditLoggerInterface
  - logTokenAccess(TelegramBot $bot, string $serviceName, string $action): void
  - logError(TelegramBot $bot, string $serviceName, string $errorCode, string $summary): void

App\Contracts\Telegram\TelegramTransportInterface
  - sendMessage(string $token, int|string $chatId, string $text, array $options = []): TelegramSendResult
  - callMethod(string $token, string $method, array $params): TelegramSendResult
```

### 6.3 Services

```
App\Services\Telegram\BotResolver           implements BotResolverInterface
  - Resolves slug → TelegramBot model
  - Per-request cache (avoid repeated DB queries)
  - Environment isolation: enforces app.env matches bot.environment
  - Fallback: reads config('services.*') during migration period (logged as deprecation)

App\Services\Telegram\BotSecretProvider     implements BotSecretProviderInterface
  - Decrypts active secret version
  - Per-request cache for decrypted tokens
  - Triggers audit log on every token access

App\Services\Telegram\BotAuditLogger        implements BotAuditLoggerInterface
  - Writes to telegram_bot_access_logs
  - Captures request_id from middleware (X-Request-Id or generated)

App\Services\Telegram\TelegramTransport     implements TelegramTransportInterface
  - Single HTTP client for all Telegram API calls
  - Handles rate limiting (429), permanent errors (400/403)
  - Never logs token values
  - Updates bot.last_used_at on success, bot.last_error_* on failure

App\Services\Telegram\TelegramClientFactory
  - Creates configured transport instances
  - Wires bot resolution + secret provider + audit logger

App\Services\Telegram\BotRotationService
  - Rotates token: creates new secret version, marks old as revoked
  - Clears per-request caches
  - Logs rotation event
```

### 6.4 DTOs

```php
App\DTOs\Telegram\ResolvedTelegramBot
  - readonly int $id
  - readonly string $slug
  - readonly string $name
  - readonly ?string $botUsername
  - readonly BotStatus $status
  - readonly BotEnvironment $environment
  - readonly ?array $metadata

App\DTOs\Telegram\TelegramSendResult
  - readonly bool $ok
  - readonly ?int $messageId
  - readonly ?int $errorCode
  - readonly ?string $errorDescription
  - readonly ?int $retryAfter
```

### 6.5 Enums (Backed)

```php
App\Enums\BotStatus: string        { Active = 'active'; Disabled = 'disabled'; Revoked = 'revoked'; }
App\Enums\BotEnvironment: string   { Production = 'production'; Staging = 'staging'; Development = 'development'; }
App\Enums\SecretStatus: string     { Active = 'active'; Pending = 'pending'; Revoked = 'revoked'; }
App\Enums\AccessAction: string     { TokenRead = 'token_read'; MessageSent = 'message_sent'; WebhookSet = 'webhook_set'; WebhookReceived = 'webhook_received'; Error = 'error'; TokenRotated = 'token_rotated'; TokenRevealed = 'token_revealed'; }
App\Enums\AccessResult: string     { Success = 'success'; Denied = 'denied'; NotFound = 'not_found'; Error = 'error'; }
```

### 6.6 Exceptions

```
App\Exceptions\Telegram\BotNotFoundException              — slug not in DB and no legacy fallback
App\Exceptions\Telegram\BotDisabledException              — bot exists but status != active
App\Exceptions\Telegram\BotEnvironmentMismatchException   — bot.environment != app.env
App\Exceptions\Telegram\BotSecretUnavailableException     — no active secret version for bot
```

### 6.7 Global Helper

```php
// app/Helpers/telegram_bot.php (autoloaded via composer.json)
function telegram_bot(string $slug): ResolvedTelegramBot
```

### 6.8 Filament Resource

```
App\Filament\Resources\TelegramBotResource  (replaces empty stub)
  - List: name, slug, status badge, environment, last_used_at, error indicator
  - Create/Edit: name, slug, bot_username, description, status, environment, metadata (key-value repeater)
  - Token field: password input, never echoed back, stored via BotSecretProvider
  - View page: masked token (first 4 + last 4 chars), access log relation
  - Actions: "Test Connection" (calls getMe), "Set Webhook", "Rotate Token"
  - Policy: Shield RBAC with custom "reveal_token" gate
```

---

## 7. Violations Found

### 7.1 `env()` Outside Config Files (6 locations)

These violate Laravel convention. After `php artisan config:cache`, `env()` returns `null` in production.

| File | Line | Call |
|------|------|------|
| `app/Services/TelegramBotService.php` | 16 | `env("TELEGRAM_BOT_TOKEN")` |
| `app/Services/OwnerAlertService.php` | 23-24 | `env('OWNER_ALERT_BOT_TOKEN')`, `env('OWNER_TELEGRAM_ID')` |
| `app/Http/Controllers/OwnerBotController.php` | 19, 192, 233 | `env('OWNER_ALERT_BOT_TOKEN')`, `env('TELEGRAM_CASHIER_BOT_TOKEN')`, `env('OWNER_TELEGRAM_ID')` |
| `app/Console/Commands/QueueHealthCheck.php` | 54-55 | `env('OWNER_ALERT_BOT_TOKEN')`, `env('OWNER_TELEGRAM_ID')` |
| `app/Console/Commands/TourSendReminders.php` | 23-25 | `env('OWNER_ALERT_BOT_TOKEN')`, `env('OWNER_TELEGRAM_ID')`, `env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE')` |
| `app/Observers/BookingObserver.php` | 22 | `env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE')` |

### 7.2 Token Serialized to Queue Payload

`SendTelegramNotificationJob` accepts `$botToken` as a constructor parameter. Because the job implements `ShouldQueue`, the token is serialized as plaintext into the queue storage (database `jobs` table or Redis).

**Impact:** Anyone with database/Redis read access can extract all bot tokens from the jobs table.

### 7.3 Inconsistent Config Paths

`TELEGRAM_BOT_TOKEN` is referenced via 3 different config paths:
- `services.telegram.bot_token`
- `services.telegram_bot.token`
- `services.telegram_booking_bot.token`

`TELEGRAM_BOT_TOKEN_DRIVER_GUIDE` is referenced via 2 paths:
- `services.telegram_bot.bot_token` (confusing — nested under `telegram_bot`)
- `services.driver_guide_bot.token`

### 7.4 Cashier Bot Silent Fallback

`CashierBotController` line 43:
```php
$this->botToken = config('services.cashier_bot.token', config('services.owner_alert_bot.token'));
```
If `CASHIER_BOT_TOKEN` is not set, it silently uses the Owner Alert bot. Messages sent via this fallback go to the wrong bot identity.

---

## 8. Risk List

| # | Risk | Severity | Mitigation |
|---|------|----------|------------|
| R1 | **Token in queue payload** — `SendTelegramNotificationJob` serializes plaintext token | HIGH | Phase 2: refactor job to accept bot slug, resolve token at execution time |
| R2 | **8 of 9 webhooks unprotected** — any IP can POST forged updates | HIGH | Phase 3: apply `VerifyTelegramWebhook` (or new generic version) to all webhook routes, register secrets with Telegram |
| R3 | **env() in 6 non-config files** — breaks under `config:cache` | MEDIUM | Phase 2: remove all `env()` fallbacks when migrating to registry |
| R4 | **Migration downtime** — if DB is unavailable, all bots stop | MEDIUM | Keep `config/services.php` as fallback during Phase 2, remove in Phase 4 |
| R5 | **Cashier bot silent fallback** — wrong bot identity used | LOW | Phase 2: throw `BotSecretUnavailableException` instead of silent fallback |
| R6 | **Config path confusion** — 3 paths for same token | LOW | Phase 2: all resolve to slug `main` via registry |
| R7 | **Encryption key rotation** — if APP_KEY changes, all encrypted secrets become unreadable | MEDIUM | Document: must re-encrypt all secrets after key rotation. Add artisan command. |
| R8 | **Seeder reads .env at migration time** — one-time exposure | LOW | Run seeder only from CLI, never via web. Document in runbook. |

---

## 9. Rollout Stages

### Stage 1: Foundation (branch: `feature/telegram-mgmt-foundation`)
- Migrations: `telegram_bots`, `telegram_bot_secrets`, `telegram_bot_access_logs`
- Models: `TelegramBot`, `TelegramBotSecret`, `TelegramBotAccessLog`
- Enums: `BotStatus`, `BotEnvironment`, `SecretStatus`, `AccessAction`, `AccessResult`
- Exceptions: all 4
- Contracts: all 4 interfaces
- **Test:** migration up/down, model factory, enum casting
- **Risk:** None — no production behaviour changes
- **Rollback:** `php artisan migrate:rollback --step=3`

### Stage 2: Service Layer (branch: `feature/telegram-mgmt-services`)
- Services: `BotResolver`, `BotSecretProvider`, `BotAuditLogger`, `TelegramTransport`, `TelegramClientFactory`
- DTOs: `ResolvedTelegramBot`, `TelegramSendResult`
- Helper: `telegram_bot()` global function
- ServiceProvider bindings (interface → implementation)
- **Seeder:** reads current `config('services.*')` values, creates bot records with encrypted tokens
- **Test:** resolver caching, secret decryption round-trip, environment isolation, audit log writes
- **Risk:** R4 (DB unavailable). Mitigated by fallback to config.
- **Rollback:** Remove service provider bindings. Old code still reads `config()` directly.

### Stage 3: Filament UI (branch: `feature/telegram-mgmt-filament`)
- `TelegramBotResource` (full CRUD replacing stub)
- `TelegramBotPolicy` (Shield integration)
- Access log relation manager
- Actions: Test Connection, Set Webhook, Rotate Token
- **Test:** Filament Livewire tests for create/edit/list, policy gates
- **Risk:** None — additive UI only
- **Rollback:** Set `$shouldRegisterNavigation = false`

### Stage 4: Consumer Migration (branch: `feature/telegram-mgmt-migrate-consumers`)
- Refactor all controllers/services/commands/jobs/observers to use `telegram_bot()` helper
- Fix `SendTelegramNotificationJob` to accept slug instead of raw token
- Remove all `env()` fallbacks from non-config files
- Remove silent cashier→owner fallback
- Apply webhook secret middleware to all 9 routes
- **Test:** integration tests per consumer, webhook validation tests
- **Risk:** R1, R2, R3, R5. This is the highest-risk stage.
- **Rollback:** Revert branch. `config/services.php` still works.

### Stage 5: Cleanup (branch: `chore/telegram-mgmt-cleanup`)
- Remove legacy `config/services.php` telegram sections
- Remove fallback logic from `BotResolver`
- Remove `BotConfiguration` model and empty resource (replaced)
- Add `BotRotationService`
- Add artisan command for key rotation re-encryption
- **Test:** full regression, verify no consumer reads `config('services.telegram*')` directly
- **Risk:** R7. Document key rotation procedure.
- **Rollback:** Restore `config/services.php` sections from git.

---

## 10. Test Matrix

| Layer | What to Test | Tool | Stage |
|-------|-------------|------|-------|
| Unit | Enum casting, DTO construction, exception messages | PHPUnit | 1 |
| Unit | BotResolver: slug lookup, caching, environment enforcement | PHPUnit + mock | 2 |
| Unit | BotSecretProvider: encrypt/decrypt round-trip, version ordering | PHPUnit | 2 |
| Unit | TelegramTransport: rate limit handling, error classification | PHPUnit + Http::fake | 2 |
| Unit | BotAuditLogger: log creation, metadata capture | PHPUnit | 2 |
| Integration | Seeder: creates 7 bots with correct slugs and encrypted tokens | PHPUnit + DB | 2 |
| Integration | Filament CRUD: create bot, verify token encrypted in DB | Livewire test | 3 |
| Integration | Consumer refactor: each controller resolves correct bot | PHPUnit + Http::fake | 4 |
| Integration | Webhook middleware: rejects bad secret, accepts good secret | PHPUnit | 4 |
| Integration | Job refactor: `SendTelegramNotificationJob` resolves slug at runtime | PHPUnit | 4 |
| E2E | `AssertProductionConfig`: validates all 7 bots exist and have active secrets | Artisan command | 5 |

---

## 11. Rollback Strategy

**Per-stage rollback** — each branch is independently revertable:

1. **Stage 1 (Foundation):** `php artisan migrate:rollback --step=3` — removes tables, no code depends on them yet.
2. **Stage 2 (Services):** Remove bindings from `AppServiceProvider`. Old `config()` calls still work.
3. **Stage 3 (Filament):** Set `$shouldRegisterNavigation = false`. No functional impact.
4. **Stage 4 (Consumers):** `git revert` the branch. All consumers fall back to `config()`.
5. **Stage 5 (Cleanup):** `git revert` restores `config/services.php` legacy sections.

**Nuclear rollback:** Revert all branches to `main`. The system returns to current `.env`-based operation with zero data loss.

---

## 12. PR Summary

### Goal
Audit and document every Telegram bot token, webhook secret, config path, and API consumer in the codebase. Produce a migration plan for the Telegram Bot Management System.

### Scope
- [x] Searched all PHP files for `env()`, `config()`, `api.telegram.org` references
- [x] Identified 7 logical bots across 10+ config paths
- [x] Mapped all 21 consumer locations (controllers, services, jobs, commands, observers)
- [x] Documented 9 webhook routes and their security status
- [x] Found 6 `env()` violations, 1 token-in-queue vulnerability, 8 unprotected webhooks
- [x] Proposed target architecture: models, contracts, services, DTOs, enums, exceptions
- [x] Defined 5-stage rollout with per-stage rollback
- [x] Created test matrix covering unit, integration, and E2E
- [x] No production code changed

### Risks
- This PR changes no production behaviour
- The audit may have missed Telegram usage in Livewire components or blade templates (searched but found none)

### Rollback
Delete the branch. No code was changed.

### Manual Verification
1. Verify all 7 bots listed match your `.env` on VPS
2. Confirm the slug names make sense for your team
3. Review the metadata fields (chat_ids, webhook_urls) — decide which go in `metadata` JSON vs dedicated columns
4. Confirm the 5-stage rollout order works for your deployment cadence
