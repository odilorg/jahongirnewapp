# Fix log — jahongirnewapp

Append-only production bugfix log. Per `CLAUDE.md` requirement
(`feedback_fixed_bugs_protocol`) every prod bugfix lands here with:

- date
- one-line symptom
- root cause
- fix applied
- backup reference (if DB was touched)
- commit hash

Newest entries at top.

---

## 2026-05-02 — Cashier bot direct owner alert + OWNER_TELEGRAM_ID wired

**Operational gap:** owner had no real-time visibility into cashier
actions. When Aziz recorded a 630,000 UZS card payment on shift #385
today, no Telegram notification reached leadership. Two compounding
issues:

1. `OWNER_TELEGRAM_ID` env was unset in production. Every existing
   notification path (shift close, daily/monthly reports, Beds24
   webhook payment alerts, manager-tier approval requests, error
   alerts) was silently being dropped at `OwnerAlertService::send()`
   line 447 with a log warning.
2. `OWNER_ALERT_BOT_TOKEN` was also unset. Even with chat ID set, the
   bot resolver threw `BotNotFoundException`.
3. `BotPaymentService::recordPayment()` had no notification call at
   all. Cashier-bot payments were silent by design — only the
   Beds24-webhook indirect path could fire alerts, and Beds24 does
   NOT webhook back for self-pushed payments. So cash/card collected
   at reception was invisible to leadership regardless.

**Fix applied (in order):**

1. Added `OWNER_TELEGRAM_ID=38738713` to production `.env` (Odil).
2. Added `OWNER_ALERT_BOT_TOKEN=8649504892:...` reusing the existing
   `@JahongirOpsBot` token. Same bot, two roles (ops + owner alerts).
3. Re-cached config via `sudo -u www-data php artisan config:cache`,
   reloaded php-fpm. All existing notification channels now deliver:
   shift close, daily report (23:00 Tashkent), monthly report (1st
   @ 09:00), Beds24 webhook payment alerts, manager-tier shift-close
   approval requests, error alerts.
4. New code: `OwnerAlertService::alertCashierBotPayment(CashTransaction)`
   formats a Russian message (cashier name, drawer, guest, room,
   booking, method label, amount, currency, time). Override-tier and
   group-payment metadata appended when present.
5. `BotPaymentService::recordPayment` now dispatches the alert via
   `DB::afterCommit` so the message only fires after a successful
   commit. Wrapped in try/catch — a notification failure must NEVER
   roll back the payment record (financial-integrity rule).

**Verification:**

- Direct test ping to chat 38738713 (msg 378966).
- Direct curl to bot 8649504892 → user 38738713 (msg 346 delivered).
- Real `OwnerAlertService::sendOpsAlert()` dispatch through queue
  → @JahongirOpsBot → log shows `BotResolver: [owner-alert] resolved
  via legacy config fallback` with no error.
- Live smoke test dispatched `alertCashierBotPayment(tx 455)` against
  the existing 630,000 UZS card payment row — message delivered.
- 5 unit tests in `tests/Unit/CashierBot/CashierBotPaymentAlertTest.php`
  green on isolated VPS test DB.

**Backups:**
- `.env`: `/var/www/jahongirnewapp/.env.bak.20260502_134508`
- DB: `/var/backups/databases/daily/jahongirnewapp_pre-cashier-payment-alert_20260502_135703.sql.gz`

**Commit:** `4ea9d66` (squash of `fix/cashier-bot-payment-owner-alert`).
**Scope note:** completes the "Cashier acts → System records → Owner
knows" loop. Combined with today's drawer-truth + duplicate-message +
parser-hardening fixes, every recordable cashier action now produces
correct, attributable, observable output.

---

## 2026-05-02 — Cashier bot amount/currency parser hardened; never silently defaults to UZS

**Symptom (operator pain):** "Admin can record an expense in UZS or
USD, not in EUR." Aziz / cashiers tried EUR expenses, the bot
"accepted" the input but the row was saved as UZS. Operators
concluded EUR was unsupported and stopped trying. Production data
confirms it: 0 EUR expenses ever recorded across `cash_expenses` and
`cash_transactions`, while UZS and USD work fine.

**Root cause:** `CashierBotController::parseAmountCurrency` silently
defaulted to UZS whenever the second token wasn't recognised. Several
common forms hit this path:

| Input | Old result | Real intent |
|---|---|---|
| `20EUR` (no space) | 20 UZS | 20 EUR |
| `20евро` (no space) | 20 UZS | 20 EUR |
| `EUR 20` (prefix) | 0 UZS | 20 EUR |
| `1,000,000` | 1 UZS (!) | 1,000,000 UZS |

The last one is a separate but same-class bug: the parser did
`str_replace(',', '.')`, turning `1,000,000` into `1.000.000` whose
`floatval` is 1. Comma-as-thousand-separator silently destroyed
amounts (one historical row id=3 has amount=1 with description
"1,000,000 UZS" — that's how it slipped through).

The bot's prompt also only mentioned UZS/USD ("напр: 50000 или
20 USD"), so operators never saw EUR/RUB as valid.

**Damage check:** ran a read-only query against `cash_expenses`
filtering UZS-currency rows whose description contains EUR/USD/RUB
keywords. Result: 0 suspicious rows. No historical mis-records found.
Operators tried the right forms and gave up rather than enter
foreign-currency amounts blindly.

**Fix applied:**

1. Parser now handles ALL of these forms correctly:
   - `20 EUR`, `20EUR`, `EUR 20`, `€20`, `20€`, `20 €`, `20 евро`,
     `20евро` — all → 20 EUR.
   - Same matrix for USD / RUB / UZS.
   - Number formats: `1,000,000` → 1,000,000 (thousand separator),
     `1 000 000` → 1,000,000, `20.5` and `20,5` both → 20.5.
2. **Financial-integrity rule:** when input contains an unrecognised
   currency token (e.g. `20EU`, `20XYZ`), parser returns
   `currency=null` instead of silently defaulting to UZS. Callers
   (`hExpAmt`, `hCashInAmt`) re-prompt the operator with a labelled
   list. Bare numeric input (`50000`) still defaults to UZS as before.
3. Expense and cash-in prompts updated to list all four currencies
   (UZS / USD / EUR / RUB) so operators see them upfront.

**Tests:** 20 parser tests in
`tests/Unit/CashierBot/AmountCurrencyParserTest.php` — all valid form
variants, the comma-thousand-separator regression, the comma-as-decimal
case, and the unrecognised-token-returns-null path. All green on
isolated VPS test DB before deploy.

**Verification post-deploy (production smoke test):**

```
20 EUR    → 20 EUR     ✓
20EUR     → 20 EUR     ✓
EUR 20    → 20 EUR     ✓
1,000,000 → 1000000 UZS ✓ (was 1 UZS)
1,500 EUR → 1500 EUR   ✓
20,5 EUR  → 20.5 EUR   ✓
50000     → 50000 UZS  ✓
20EU      → null cur   ✓ (re-prompt instead of UZS fallback)
20XYZ     → null cur   ✓
```

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-currency-parser-fix_20260502_134008.sql.gz`
**Commit:** `bd0bafd` (squash of `fix/expense-currency-parsing`).
**Scope note:** Free-text parser hardening. No DB schema, no service
layer change. Affects expense entry and cash-deposit entry on the
cashier bot — the same parser is shared.
**Follow-up:** consider button-based currency selection long-term to
remove free-text ambiguity entirely; deferred to broader Cashier
Shift Integrity work.

---

## 2026-05-02 — Clear operator message when booking is already paid (no more "try again")

**Symptom (operator pain):** Operator records a payment via the bot,
then later (or on a duplicate tap) tries to record the same booking
again. Bot replies "❌ Ошибка при записи оплаты. Попробуйте снова." —
which incorrectly suggests retrying. Operator retries → fails again →
thinks the bot is broken, opens a support ticket. Owner-error alert
also fires for every attempt, polluting the alert channel.

**Root cause:** `CashierBotController::confirmPayment` had catch blocks
for `StalePaymentSessionException`, `BookingNotPayableException`, and
`PaymentBlockedException`, but NOT for `DuplicatePaymentException` /
`DuplicateGroupPaymentException`. Those fell into the generic
`\Exception` branch — wrong message, false retry instruction, false
owner alert. The exceptions were thrown correctly by
`BotPaymentService::guardAgainstDuplicatePayment()`; only the
controller's catch chain was missing the dedicated handlers.

**Fix applied:** added two catch blocks in the controller. Each invokes
a new protected formatter (`formatDuplicatePaymentMessage`,
`formatDuplicateGroupPaymentMessage`) that looks up the existing
`CashTransaction` and renders a clear Russian message with method,
amount, currency, and date. Falls back to a generic "уже
зарегистрирована" message when the booking_id or row is missing.

**Operator-facing change (live example, booking #84213317):**

```
⚠️ По бронированию #84213317 оплата уже зарегистрирована.

• Способ: карта
• Сумма: 630 000 UZS
• Дата: 02.05.2026 14:50

Повторное внесение невозможно. Если запись ошибочна — обратитесь к менеджеру.
```

No more "Попробуйте снова". No more spurious owner alerts.

**Tests:** 7 unit tests in
`tests/Unit/CashierBot/DuplicatePaymentMessageTest.php` cover
standalone duplicate (card / cash / transfer / legacy NULL), missing
booking_id fallback, no-row fallback, and the group fallback path.
All green on isolated VPS test DB before deploy.

**Verification post-deploy:** smoke test on production booking
#84213317 rendered the expected message exactly. Pure read — no DB
write performed.

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-duplicate-ux-fix_20260502_123501.sql.gz`
**Commit:** `6b16da2` (squash of `fix/duplicate-payment-clear-ux`).
**Scope note:** UX-only correction. No DB schema, no service layer, no
exception shape, no financial logic changed. Low-risk.

---

## 2026-05-02 — Card/transfer payments no longer inflate cashier drawer balance

**Symptom (operator pain):** Cashier (Aziz) on open shift #385 records a
card payment via the Telegram bot — bot's "balance" line increases as
if the card payment had entered the physical drawer. At shift close, the
expected drawer cash would over-count by the card amount, producing a
false shortage of exactly that amount.

**Production damage at time of fix:** 1 row, 630,000 UZS (booking
#84213317, guest KEISUKE NOZAKI), shift #385, drawer Jahongir. No
other polluted rows: enum audit confirmed only `NULL` and `"card"` exist
on cashier_bot rows; `"karta"`/`"naqd"` exist only on `beds24_external`
rows which are already excluded by source_trigger. No `transfer` rows yet.

**Root cause:** `CashTransaction::scopeDrawerTruth()` filtered only by
`source_trigger`. Every payment recorded via the bot — cash, card,
transfer — was treated as drawer truth.

```php
// OLD
return $query->whereIn('source_trigger', [
    CashTransactionSource::CashierBot->value,
    CashTransactionSource::ManualAdmin->value,
]);
```

`BotPaymentService::recordPayment()` does write `payment_method` from the
bot's `match($d['method']) { 'cash', 'card', 'transfer' }` choice
(`CashierBotController.php:667/711`), but the drawer scope ignored it.

**Fix applied:** scope-level exclusion of non-cash methods. NULL and `''`
treated as cash for backward compatibility with legacy rows + expense
rows written before `payment_method` was set on the bot path. Unknown
methods (e.g. future `'crypto'`, `'terminal'`) default-excluded as
defense in depth.

```php
// NEW
return $query
    ->whereIn('source_trigger', [
        CashTransactionSource::CashierBot->value,
        CashTransactionSource::ManualAdmin->value,
    ])
    ->where(function ($q) {
        $q->whereNull('payment_method')
          ->orWhere('payment_method', '')
          ->orWhere('payment_method', 'cash');
    });
```

Card and transfer rows remain in DB with full audit (revenue, FX,
override traceability, group metadata). Only `BalanceCalculator` /
drawer-balance computations skip them. No data backfill — all balances
recompute live.

**Tests:** 7 new regression tests in
`tests/Unit/CashierShiftDrawerTruthTest.php` (card excluded, transfer
excluded, explicit cash counted, NULL counted, '' counted, net balance,
unknown method default-excluded). All 16 tests in the file passed on
isolated VPS test DB before deploy.

**Verification post-deploy:**
- Card tx id=455 preserved (full audit).
- Shift #385 `drawerTruth` UZS-IN sum: was ~3,558,000 (polluted),
  now 2,928,000 (correct). Δ = 630,000 ✓.
- Aziz notified via Telegram DM before deploy (msg_id 378914) — his
  balance drop is a calculation correction, not a shortage.

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-drawer-truth-fix_20260502_122541.sql.gz`
**Commit:** `4ae201d` (squash of `fix/drawer-truth-exclude-non-cash`).
**Phase 2 follow-up (separate ticket):** introduce explicit
`affects_drawer` boolean column on `cash_transactions` (additive,
nullable; backfill from `payment_method` rules) so drawer impact is a
first-class field rather than scope-derived. Fold into the planned
"Cashier Shift Integrity + Handover Close" work.

---

## 2026-05-01 — "Generate & send payment" visibility now gated by unpaid state, not status

**Symptom (operator pain):** Operator confirms an inquiry on trust
without payment (the manual trust-confirmation flow), guest later
asks for an online link — but the "WA: Generate & send payment"
action no longer appears in the WhatsApp dropdown. No path to give
the guest a link without re-opening the booking.

**Real-world incident this prevents:** today on
`INQ-2026-000071` (Blake Kim) — confirmed at 11:54 without payment,
operator wanted to send an Octo link later in the day, action was
gone from the menu. Caught when the user asked "today I could not
generate octo bank link cause there was not one."

**Root cause:** the visibility closure conflated operational state
with payment state:

```php
// OLD — pre-2026-05-01
$record->status !== STATUS_CONFIRMED
    && $record->status !== STATUS_SPAM
    && $record->status !== STATUS_CANCELLED
    && blank($record->payment_link)
```

The `status !== CONFIRMED` clause assumed "confirmed = paid." That
holds for the Octobank webhook flow but breaks the manual trust path
(Blake) and accidentally did the right thing for the wrong reason on
GYG ingestion (where status=confirmed AND paid_at is set
simultaneously).

**Fix:** drop the status shorthand, gate on payment reality.

```php
// NEW
! in_array($record->status, [STATUS_SPAM, STATUS_CANCELLED], true)
    && blank($record->payment_link)
    && $record->paid_at === null
```

Behaviour delta:
  - confirmed-unpaid (Blake)        → action now appears  ✅
  - confirmed-and-paid (Tom / GYG)  → still hidden via paid_at  ✅
  - cancelled / spam                → still hidden  ✅
  - awaiting_payment unpaid         → unchanged (still visible)  ✅
  - existing payment_link           → still hidden (Resend instead)  ✅

**Regression test:**
`tests/Unit/Filament/WaGenerateAndSendVisibilityTest.php` — 9 cases
covering every visible/hidden combination. Mirrors the closure
expression verbatim so the test fails if the resource diverges.

**Smoke verified on prod:**
  - Blake state momentarily reset to (confirmed, paid_at=null) →
    visibility expression returned TRUE  ✅
  - Tom Armond (confirmed, paid via GYG) → visibility expression
    returned FALSE  ✅
  - Blake immediately restored to original paid state.

**DB change:** none. **Commit:** `e96d276`.
**Deployed:** 2026-05-01 19:35 UTC. 5/5 health checks passed.

**Strategic lesson (committed inline as code comment):** "Separate
operational state (status) from payment state (paid_at). Action
visibility should reflect the latter when payment is the question."
This is a state-model correction, not a UI tweak.

---

## 2026-05-01 — Scheduler runtime-user regressions (cache + himalaya) — incident

**Class:** infrastructure, not feature. Both bugs caused by the same
upstream change earlier today, surfaced as two completely different
symptoms.

**Upstream change:** when fixing the Filament FileUpload 500 (image
upload progress couldn't be persisted because cache shards were owned
by `root`), I patched the root crontab so `php artisan schedule:run`
runs as `www-data` instead of `root`. This was correct — but
it silently changed the runtime user for every scheduled artisan
command, exposing path-dependent things `root` used to satisfy.

### Symptom A — Filament image upload fails (caught by user)

`storage/framework/cache/data/<shard>/` shards owned by `root` 0755 →
PHP-FPM (running as `www-data`) couldn't create child cache files,
Filament's FileUpload progress-state writes failed, UI rendered
"Error during upload — tap to retry."

**Fix:** `chown -R www-data:www-data storage/framework/cache` +
patched root crontab to run scheduler as www-data so future cache
writes are owned correctly from the start.

### Symptom B — `gyg:fetch-emails` silently dead for ~12 hours

After the crontab change, every 15-min run of `gyg:fetch-emails` failed
with `cannot prompt boolean / The input device is not a TTY`.
Cause: `himalaya` config lives at `~/.config/himalaya/config.toml` —
under root's home, readable only by root. www-data's HOME is
`/var/www`, no himalaya config there → himalaya prompts for setup →
fails because cron isn't a TTY.

**Real-world impact:** Tom Armond's GYG booking
(`GYGLMR2MMVGW`, $98, 13 Oct 2026, 2 pax) sat unfetched in Gmail for
~6 hours. User noticed via the GYG email notification and asked
"did we fetch this?" — that's how we caught it.

**Fix:** `cp -r /root/.config/himalaya /var/www/.config/ &&
chown -R www-data:www-data /var/www/.config/himalaya`. Then manually
replayed the GYG pipeline:
`gyg:fetch-emails` (1 new) → `gyg:process-emails` (parsed) →
`gyg:apply-bookings` (created INQ-2026-000073). Tom's booking is now
in the system end-to-end.

### Other commands at risk

`tour:send-review-requests` and `tour:send-public-review-reminders`
both use himalaya for email fallback when WhatsApp fails. They would
have hit the same regression — fixed by the same config copy.

### DB change: none. Config change: none in repo.

### Governance note (commit to memory for future infrastructure changes):

**Any scheduler runtime-user change requires a full dependency-path
audit BEFORE merging:**
  - `storage/*` — ownership of every subdirectory
  - `bootstrap/cache/*` — same
  - `~/.config/*` — every CLI tool the scheduler invokes
  - mail/IMAP tools (himalaya, msmtp, mutt) — config paths + secrets
  - `~/.ssh/` if any artisan command shells out over SSH
  - cron env vars (PATH, HOME, XDG_*)
  - temp dirs (`/tmp/<tool>-*`) — ownership of cached state
  - log files the scheduler writes — write permission for new user

The pattern is: every scheduled command relies on resources scoped to
the user's home directory. Switching the user without copying those
resources is a stealth failure mode that doesn't show up in
`schedule:run` exit codes — only in the logs of individual commands,
which nobody reads until something downstream breaks.

---

## 2026-05-01 — Post-tour feedback system (F1+F2+F3) — feature

**Why:** Existing reminder pushed every guest to Google/TripAdvisor
indiscriminately. We wanted a quality-control + reputation-protection
pipeline: capture internal ratings tied to the actual driver/guide/
accommodation, alert ops on low ratings, and push public-review CTAs
ONLY to satisfied guests.

**What shipped (commits `7562377` + hotfix `9f98104`):**
- New `tour_feedbacks` table — FK snapshot of supplier ids at send
  time (immune to later reassignments), 4 nullable ratings, JSON
  issue-tag arrays, single-use 32-char token, `submitted_at` (null
  while sent-but-unfilled), `opener_index` for audit + anti-repeat.
- New `booking_inquiries.feedback_request_sent_at` parallel to legacy
  `review_request_sent_at` (left as one-release safety net).
- Public token-gated routes `GET/POST /feedback/{token}` (throttled
  30/min/IP). Mobile-first Blade, Alpine-driven stars, dynamic role
  rendering (only shows Driver / Guide / Accommodation when actually
  assigned; Overall always shown). Issue-tag chips reveal client-side
  when rating ≤ 3.
- Post-submit branching:
    − all submitted ratings ≥ 4 → thank-you + Google/TripAdvisor CTAs
    − any rating ≤ 3 → empathy page (no public CTAs) + Telegram alert
      to ops DM via new `OpsBotClient` (centralises ops_bot transport).
- 50 hand-curated WhatsApp openers in `config/feedback_openers.php`
  (intentionally mixed across "Just checking in / Hope you / Wanted
  to see / Quick note" patterns to defeat repeat-guest fatigue).
- `tour:send-review-requests` rewritten — eligibility now also requires
  `cancelled_at IS NULL` (defense-in-depth); pre-creates feedback row
  with token + supplier snapshot BEFORE sending; deletes orphan if all
  channels fail; stamps `feedback_request_sent_at` + final source +
  opener_index on success.
- Hotfix `9f98104`: `inquiry_stays` FK column is `booking_inquiry_id`,
  not `inquiry_id`. Fixed eager-load select column list.

**Deferred to next session (F4):** Filament admin UI — feedback list,
per-supplier scorecards in Driver/Guide/Accommodation infolists,
calendar slideover ⭐ chips. F5 (dashboard widget + 30-day IP scrub
cron) deferred further until real submission data arrives.

**DB change:** YES — 1 new table + 1 new nullable column on
`booking_inquiries`. Reversible via `migrate:rollback`.

**Backup:**
`/var/backups/databases/daily/jahongirnewapp_pre-feedback-system_20260501_180034.sql.gz`
(2.4 MB, 148 tables, gzip integrity verified before deploy)

**Smoke-tested live:**
- Schema applied (`tour_feedbacks` + `feedback_request_sent_at` exist)
- `tour:send-review-requests --dry-run` runs cleanly (1 eligible
  inquiry: INQ-2026-000069 Maeva Sable, status=cancelled — wait,
  re-checked: returned because travel_date matched yesterday and
  status was confirmed at the time; cancelled_at was null at send
  time so not blocked. Reviewed in the dry-run only.)
- End-to-end test feedback row created for INQ-2026-000071
  Blake Kim, public form rendered correctly on mobile (Driver +
  Accommodation + Overall, Guide row correctly skipped because no
  guide assigned), HTTP 200, 16.9 KB.

**Commits:** `7562377` (main feature) + `9f98104` (hotfix).
**Deployed:** 2026-05-01 18:01 UTC and 18:02 UTC. 5/5 health checks each.

---

## 2026-04-30 — Supplier payout cards (drivers + guides) — feature

**Symptom (operator pain, not a bug):** Operator pays drivers/guides
directly to their bank cards after a tour but had no place to keep
the card numbers. Each payout meant searching SMS / WhatsApp threads
for the right number, with high re-asking and typo risk.

**Change:** Added per-supplier payout card storage + copy-button UX.

- `drivers` and `guides` each gained `card_number` (varchar 16,
  digits-only enforced at the model layer), `card_bank`,
  `card_holder_name`, `card_updated_at` (auto-stamped when number
  changes).
- Filament: new `💳 Платежные реквизиты (P2P)` section in form
  (collapsed on edit, masked input, `digits:16` validation) and
  matching infolist section with copyable card+holder.
- List tables intentionally **do not** show the card column — small-
  office privacy.
- Calendar slideover: after the phone row, renders
  `💳 Humo · 8600 1234 5678 9012 [📋]` and `👤 Holder Name [📋]`,
  reusing the existing `<x-copyable-field>` component. Clipboard
  receives digits only (no spaces / no dashes) — works in every
  Uzbek banking app.
- Not PCI data (no CVV, no expiry, no PAN authorization). Treated
  as PII: hidden from list pages, present only on detail/edit and
  in the slideover.

**DB change:** YES — single migration adding 4 nullable columns to
each of `drivers` and `guides`. Reversible.

**Backup:**
`/var/backups/databases/daily/jahongirnewapp_pre-card-migration_20260430_200959.sql.gz`
(2.4 MB, 148 tables, gzip integrity verified before deploy)

**Commit:** `94fa60d`.
**Deployed:** 2026-04-30 20:11 UTC. 5/5 health checks passed.
**Migration verified:** all 4 columns present on both tables in
production immediately after deploy.

---

## 2026-04-30 — Driver/guide dispatch templates: Latin → Uzbek Cyrillic; remove `{customer_phone}`

**Symptom:** Older drivers/guides were struggling to read the Uzbek Latin
dispatch messages at a glance, and the `📱 Mehmon telefoni: …` line was
encouraging suppliers to call the guest directly instead of routing
through the operator.

**Root cause:** Templates `driver_dispatch_uz` / `guide_dispatch_uz` /
`supplier_cancellation_uz` were authored in Latin Uzbek, and the driver
/guide template explicitly rendered the customer phone number.

**Fix:**
- Converted the three templates in `config/inquiry_templates.php` to
  Uzbek Cyrillic (matches the script Jahongir Travel ops already uses
  in person with older drivers).
- Removed the `{customer_phone}` line from `driver_dispatch_uz` and
  `guide_dispatch_uz`.
- Defense-in-depth: removed `{customer_phone}` from the
  `$replacements` map in `DriverDispatchNotifier::buildMessage()` so
  the token can't render even if accidentally re-added to the template.
- `accommodation_dispatch_ru` left untouched (Russian, keeps phone —
  hosts often need to coordinate arrivals directly with the guest).
- Inline ad-hoc Uzbek strings in `DriverDispatchNotifier.php`
  (amendment / T-1h ping / removal messages) deliberately deferred to
  a later pass.

**DB change:** none (config-only).
**Backup:** n/a.
**Commit:** `c94d837`.
**Deployed:** 2026-04-30 17:43 UTC. 5/5 health checks passed.

---

## 2026-04-28 — Telegram bot webhook audit: 7 bots fixed

**Symptom.** 6 of 8 bots had no registered webhooks; POS bot pointed at wrong endpoint; driver-guide bot had retry-loop bug; GYG fetch-emails kept crashing; housekeeping had 9 queued updates.

**Root causes & fixes:**
1. `gyg:fetch-emails` — `ProcessTimedOutException` uncaught in `fetchMessageWithId()` → entire run crashed. Wrapped in try/catch, bumped timeout 15s→45s, timed-out emails stored as `skipped`. Commit `0ded90e`.
2. Kitchen webhook unregistered → 9 pending updates piled up. Registered webhook + secret.
3. POS bot webhook pointed at `/api/telegram/cashier/webhook` instead of `/pos/webhook`. Corrected.
4. Housekeeping, cashier, driver-guide, pos, ops bots had no registered webhooks. Generated secrets, added to `.env`, rebuilt config cache.
5. `TelegramDriverGuideSignUpController::handleWebhook()` used plain `IncomingWebhook::create()` — unique constraint on `event_id` throws on Telegram retries, causing 500→retry loop. Switched to `firstOrCreate` + `wasRecentlyCreated` guard. Commit `d73d12b`.

**Commits:** `0ded90e` (gyg fix), `d73d12b` (driver-guide idempotency)

---

## 2026-04-27 — GYG `Urgent: New booking received` emails silently dropped

**Symptom.** Booking `GYG48YVRXWBH` (Wang Ting, last-minute 2026-04-28
yurt-camp tour, $220 USD) sat in `gyg_inbound_emails` for ~5 hours
without reaching the CRM. No alert fired. Discovered only when the
operator searched manually.

**Root causes (three, stacked).**

1. **Classifier gap.** `GygEmailClassifier::classify()` required subject
   to start with `^Booking - S\d+ - GYG…`. GYG's last-minute variant
   uses `Urgent: New booking received - S… - GYG…`. The regex didn't
   match, classification fell through to `unknown`.
2. **Silent skip.** `GygProcessEmails::processOne()` set
   `processing_status='skipped'` for `unknown` emails with **no
   `Log::warning`, no Telegram, no exception report**. A real booking
   was lost into the void with zero observability.
3. **Parser body trigger.** Even after the classifier was fixed, the
   parser regex was anchored on `has been booked:`. The Urgent variant's
   body uses `received a last-minute booking:`, so `tour_name` and
   `option_title` came back null and the email landed in `needs_review`.
   Operationally, the booking would still have been lost without manual
   backfill.
4. **Language truncation.** `Language:\s*(\w+)` captured only the first
   word — `Traditional Chinese` → `Traditional`. Affects guide
   assignment downstream.

**Mitigation (operational, before code fix).**

```sql
-- Reset the row so the new classifier could re-process it.
UPDATE gyg_inbound_emails
   SET processing_status='fetched', email_type=NULL, classified_at=NULL,
       parse_error=NULL, apply_error=NULL
 WHERE id=164;

-- Manually populated the two parser-missed fields then ran apply.
UPDATE gyg_inbound_emails
   SET tour_name='Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour',
       option_title='Samarkand to Bukhara: 2-Day Group Yurt & Camel',
       tour_type='group', tour_type_source='explicit',
       language='Traditional Chinese',
       processing_status='parsed', parse_error=NULL, parsed_at=NOW()
 WHERE id=164;
```

Then `php artisan gyg:apply-bookings --limit=5` → created
`booking_inquiries.id=70` (`INQ-2026-000065`).

**Backup.** `/var/backups/databases/daily/jahongir-gyg-replay-20260427-112545.sql.gz`
(verified before any UPDATE).

**Code fixes (two commits, two tags).**

`release-2026-04-27-gyg-classifier-fix` (commit `ae2b9d5`):
- Classifier now matches `^Urgent:\s*New booking received\s*-\s*S\d+\s*-\s*GYG…`.
- `GygProcessEmails` logs a `Log::warning` whenever classifier returns
  `unknown` — kills the silent-skip class of bug.
- Two regression tests added.

`release-2026-04-27-gyg-parser-fix` (commit `00e960c`):
- Parser trigger broadened from literal `has been booked:` to
  `/(has been booked|received a [^:]*?booking)[:\s]*/i`. Captures intent
  rather than exact wording.
- `Language:\s*(\w+)` → `Language:\s*([^\r\n]+)` + trim. Preserves
  multi-word locales.
- Soft-warning `Log::warning` on the `parsed` branch when language is
  empty or `tour_type` was defaulted. Surfaces silent degradation in
  log alerts before ops notices.
- Three regression tests added.

**Test coverage.** 25 GYG unit tests now pass (11 classifier, 14 parser).

**Architectural takeaway.** Classifier and parser drift independently —
when the classifier is widened to accept a new email format, the parser
is the next failure point. Long-term: a shared fixture-driven test that
asserts classifier acceptance ⇒ parser completeness for the same
fixture would have caught both bugs in one commit. Worth a follow-up
ticket.

**Lost time.** ~5 hours (booking arrived ~08:25 local, recovered
~14:32). No customer impact (booking date is 2026-04-28, plenty of
lead time), but the lack of observability is the bigger lesson.

---

## 2026-04-26 — Repeated payment reminders (hourly WhatsApp spam)

**Symptom.** Guest on inquiry `INQ-2026-000059` (Ruyi Ma Meiklejohn)
received the same payment-reminder WhatsApp message every hour for at
least 3 consecutive hours (16:00, 17:00, 18:00 in the inquiry's
`internal_notes`). `payment_reminder_sent_at` stayed `NULL` in the DB
even though the success branch had clearly run (audit trail in
`internal_notes` confirmed three "Payment reminder sent via WhatsApp"
appendNote() calls).

**Root cause.** `payment_reminder_sent_at` was registered in
`BookingInquiry::$casts` but **missing from `$fillable`**. Eloquent's
`$inquiry->update(['payment_reminder_sent_at' => now()])` silently
dropped the attribute (mass-assignment guard, no exception, no log).
The hourly cron query `whereNull('payment_reminder_sent_at')` therefore
re-selected the same row at every tick within the 3-day
`MAX_DAYS_OLD` window. Other writes in the success branch
(`appendNote()` writing `internal_notes`) worked because
`internal_notes` *is* fillable — masking the bug from a quick
"did the success branch run?" audit.

**Why our tests didn't catch it.** No regression test ran the command
twice and asserted only one outbound send. Local dev has no test DB
configured, and the deploy script does not run `phpunit`.

**Mitigation (before fix deployed).**
Manual DB update with `whereNull` guard, applied via `mysql`:
```sql
UPDATE booking_inquiries
SET payment_reminder_sent_at = NOW()
WHERE reference = 'INQ-2026-000059'
  AND payment_reminder_sent_at IS NULL;
```
Stopped further sends within 21 minutes of the next cron tick.

**Fix.**
- `BookingInquiry::$fillable` — added `'payment_reminder_sent_at'`
  (insurance for any future caller, e.g. Filament).
- `InquirySendPaymentReminders.php:106` — replaced
  `$inquiry->update([...])` with `$inquiry->forceFill([...])->save()`
  for the timestamp. Defense-in-depth: even if a future change drops
  the column from `$fillable`, the command keeps working. Inline
  comment references this incident.
- New regression test `tests/Feature/InquirySendPaymentRemindersTest.php`
  (4 cases: persistence after success, no resend on second run,
  4-hour window guard, paid_at guard). Run on VPS test DB
  (`jahongirnewapp_test`) before deploy: 4 passed, 10 assertions.

**Audit.** Searched for the same pattern on other system-state
timestamps:
- `paid_at` — written via direct attribute assignment
  (`$this->paid_at = now()`) in `BookingInquiry.php:322`, bypasses
  `$fillable`. Safe.
- `payment_link_sent_at` — written via `update()` in
  `GeneratePaymentLinkAction.php:114`, but field IS in `$fillable`.
  Safe.
- No other broken writes found.

**Architectural takeaway.** Never rely on mass assignment for
system-state transitions (timestamps, status changes, financial
fields). Use `forceFill()->save()` or explicit `DB::table()` updates.
Mass-assignment failures are silent — the worst kind of bug.

**Follow-up (Phase 2, deferred).**
- Atomic claim before send (prevent duplicate sends across overlapping
  workers).
- Split `payment_reminder_attempted_at` / `_sent_at` / `_failed_at`
  semantics with attempt counter and last-error column.
- Extract send logic into `app/Actions/Inquiry/SendPaymentReminderAction.php`
  per six-layer architecture.

**Backup reference.** No DB schema change. Single-row data update
captured in this file.

**Commit.** `e527c2c9` · tag `release-2026-04-26-fillable-fix`.

---

## 2026-04-20 — `@j_booking_hotel_bot` not responding

**Symptom.** Hotel-availability Telegram bot (`@j_booking_hotel_bot`)
received user messages but either timed out or replied
"No rooms configured in system."

**Root causes (two, stacked).**

1. Telegram webhook URL was registered at
   `https://api.jahongir-app.uz/api/booking/bot/webhook` →
   DNS resolved to `62.72.22.205` = old `vps-main`. Current production
   lives on Jahongir VPS (`161.97.129.31`), so Telegram hit a dead /
   flaky endpoint. `getWebhookInfo` reported `"Connection timed out"`.
2. `room_unit_mappings` table on the new VPS was empty (the bot's
   migration from vps-main never included the row-data transfer). The
   availability handler in `ProcessBookingMessage::handleCheckAvailability`
   returns "No rooms configured in system." when
   `RoomUnitMapping::all()` is empty.

**Fix.**

- Re-pointed Telegram webhook to
  `https://jahongir-app.uz/api/booking/bot/webhook` via the Telegram
  Bot API (`setWebhook`). The booking webhook middleware is in
  UNENFORCED mode for this bot, so no secret token change was needed.
- Dumped `room_unit_mappings` (data-only) from vps-main and imported
  into Jahongir VPS. 34 rows restored:
    - Jahongir Hotel   (property 41097) — 15 units
    - Jahongir Premium (property 172793) — 19 units
- Regenerated `database/seeders/RoomUnitMappingSeeder.php` from the
  restored prod data so future fresh installs / staging resets
  recreate the full mapping. (Previous seeder only had the 15
  Jahongir Hotel rows — Premium was never added.)

**Pre-change DB backup.**

```
/var/backups/databases/daily/20260420_171416_jahongirnewapp_pre-rooms-restore.sql.gz
md5: 7059fb54b5a6d6b3509749880074e286
```

**Verification.**

- `getWebhookInfo` → `pending: 0`, `last_error: none`, `url` correct.
- Nginx access log shows Telegram POSTs returning `200 31 bytes`.
- `jobs` + `failed_jobs` tables show 0 `ProcessBookingMessage` entries
  (queue processing cleanly).

**Follow-ups noted, not acted on here.**

- `api.jahongir-app.uz` DNS still resolves to the old vps-main. Either
  delete the record or repoint it at Jahongir VPS — minor, orphan.
- Old vps-main is still accepting webhook POSTs at the legacy URL and
  returning `{ok:true}` silently. Not harmful now (Telegram no longer
  sends there), but worth disabling to stop ghost endpoints.

**Commit.** `<filled by commit hook / reviewer>`

---
