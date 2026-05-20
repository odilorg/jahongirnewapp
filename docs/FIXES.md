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

## 2026-05-20 — Contract TIN auto-fetch restored via Airnet UZ relay

**Symptom:** Even with the 500 stopped (entry above), the contract form's
"Create new Turfirma" inline action always fell through to the "add
manually" toast — auto-fetch had been silently dead because all three
upstream APIs required auth that the code didn't send, and didox/soliq
hosts are unreachable from this VPS over TCP.

**Root cause:**
1. Upstream tax-info providers added `X-API-KEY`/`user-key` auth that
   `TurfirmaService::fetchDataFromApis()` never set → 401 from anywhere.
2. From this VPS, TCP egress to UZ networks (`*.didox.uz`, `*.soliq.uz`,
   even Airnet UZ VPS on arbitrary ports) is blocked. ICMP works; only
   ports 80/443 and `:2222` to vps-main are open outbound.

**Fix:**
- Built a loopback-only nginx relay on Airnet UZ VPS (`46.8.194.59`).
  Two locations: `/didox/info/{tin}` (primary, richer payload including
  bank account + mfo + director-string) and `/company/info/{tin}` (soliq
  fallback). Provider API keys live in `/etc/nginx/soliq-key.conf`
  (root:600); never appear in this app's env or git.
- Relay listens on `127.0.0.1:18443` only — zero public attack surface.
- Reachability path: this VPS → vps-main:2222 (SSH jump) → Airnet:22
  (SSH tunnel forwarding `127.0.0.1:18443`). Both hops use dedicated
  ed25519 keys with `permitopen=` restricted to a single destination
  and `from=` pinned to the calling IP. Jump user runs `/usr/sbin/nologin`.
- Tunnel held open by `soliq-tunnel.service` (autossh on this VPS) with
  systemd hardening (`NoNewPrivileges`, `ProtectSystem=strict`, etc.).
- `TurfirmaService::fetchDataFromApis()` rewritten to call the relay via
  `config('services.tin_lookup.relay_url')` (env: `TIN_LOOKUP_RELAY_URL=
  http://127.0.0.1:18443`). Soliq response normalized to didox shape so
  the existing `Turfirma::create()` mapping stays uniform. Manual-entry
  fallback preserved when both providers fail.
- Unit tests cover: missing config, didox happy path, soliq fallback +
  director-object flattening, both-providers-fail.

**Verification on deploy:**
- `php artisan tinker` against the prod-loaded service returned the
  expected fields for TIN `300965341`: shortName, name, address, mfo
  (00083), account (20208…), director string (JAXANGIROV ODILJON
  SHAKIROVICH).

**Backup:** N/A — no schema or data change.

**Commit:** `fbf85c3`

**Health on deploy:** 5/5 checks passed.

---

## 2026-05-20 — Contract create 500 when TIN-lookup APIs unreachable

**Symptom:** `https://jahongir-app.uz/admin/contracts/create` → opening the
"Create new Turfirma" inline form and entering a TIN returned a 500 Server
Error. Livewire stack trace pointed to `App\Services\TurfirmaService` line 97.

**Root cause:** `TurfirmaService::fetchDataFromApis()` called three external
tax-info endpoints (`gnk-api.didox.uz`, `new.soliqservis.uz`,
`stage.goodsign.biz`) sequentially with `Http::get($url)` — no timeout and
no exception handling. From this VPS the didox + soliqservis hosts are
network-blocked (connect timeout, same egress class as the QUIC incident).
Guzzle raised `ConnectException` on the first iteration; it bubbled out of
the `foreach`, killing the entire Filament "Create option" closure and
producing a 500 — instead of the intended "All APIs down → add manually"
notification.

Diagnosis also surfaced a second, latent issue: all three upstream APIs
now respond 401 to anonymous requests, so even without the crash the loop
returned `null` and auto-fetch was silently dead. That part is restored
in a follow-up (Airnet UZ relay + `X-API-KEY`) and is **not** part of
this commit.

**Fix:** Per-call `Http::timeout(5)->connectTimeout(3)` plus a `try/catch
(Throwable)` around each request. On network failure: log a warning with
the URL/TIN/error and `continue` to the next provider. When all three fail
the existing fallback (`ValidationException` + danger toast asking the
operator to enter details manually) fires as designed.

**Backup:** N/A — no schema or data change.

**Commit:** `f8390c7`

**Health on deploy:** 5/5 checks passed.

---

## 2026-05-10 — FX staleness guard v2 (calendar-day + max-fetched_at-age hybrid)

**Type:** Re-shipped fix. Supersedes the rolled-back v1 attempt
(commit `cb54bd2`, deployed and rolled back the same day).

**Symptom — what v1 broke:** v1 used a single hourly threshold
(`fetched_at < now - fx.stale_after_hours`, default 4h). The morning
cron `fx:push-payment-options` writes once a day at 07:00 Tashkent.
With v1's semantics, by 11:00 the row was 4h old and the guard
refused payments for the rest of the day even on a perfectly healthy
day. Hourly-freshness semantics are wrong for a daily exchange-rate
system.

**v1 deploy / rollback timeline:**
- 2026-05-10 09:03:45 +0200 — v1 commit `cb54bd2` deployed.
- 2026-05-10 09:10:05 +0200 — rolled back to `2189c8a` after
  post-deploy verification surfaced the all-day-blocked behavior.
- Window: 6 minutes 20 seconds.
- User-facing outage: ZERO. No nginx hits to
  `/api/telegram/cashier/webhook` during the window, no real
  `BotPaymentService` activity, only the post-deploy tinker
  verification triggered the broken path.

**Root cause (v1):** I shipped hourly-freshness semantics for a
daily exchange-rate system. The original `config/fx.php:54` docblock
described date-based staleness; v1 implemented it literally as
`fetched_at` age in hours. Both my implementation and the deep-mode
reviewer pass missed the operational impact (focused on
implementation correctness of the threshold, not whether the
threshold's semantics matched the once-a-day cron cadence).

**Fix applied (v2):** Hybrid check at
`BotPaymentService::preparePayment` time:

  1. **Primary** — `latest_row.rate_date == today` in app timezone.
     Catches the actual cron-failure mode: cron failed today → no
     row written for today → latest row is yesterday's → refused.

  2. **Secondary** — `fetched_at` not older than
     `config('fx.fresh_fetched_max_hours')` (default 28h, env-
     tunable via `FX_FRESH_FETCHED_MAX_HOURS`). Catches the rare
     case where a row has today's `rate_date` but `fetched_at` is
     wildly old (data fix that backdated, system clock drift,
     stuck row that survived across days). 28h gives one full
     daily cycle from any 07:00 cron run plus a 4h operational
     buffer.

Both checks must pass. Either failing throws
`StaleFxRateException`. Source field
(`cbu` / `open.er-api` / `floatrates` / `manual`) is uniformly
subject to both — no per-source exemption. A manual override is
the operator's escape valve when the cron fails (admin enters a
row with `rate_date=today`, both checks pass trivially), but a
manual row from yesterday is still treated as yesterday by the
primary check.

Both Filament-admin mixed-currency path
(`RecordMixedCurrencySplitFromAdminAction`) and the cashier-bot
path (`CashierBotController::selectGuest`) flow through
`BotPaymentService::preparePayment`, so a single guard call
covers both surfaces (CLAUDE.md "no duplicated business rule").

`CashierBotController` catches `StaleFxRateException` BEFORE the
generic `\Throwable` so the log distinguishes "stale" from
"unavailable" and the operator sees:
`⏱ Курсы валют устарели. Обратитесь к менеджеру для обновления.`

**Verification:**
- 12 / 12 new tests pass on VPS test DB:

    - `todays_morning_cron_row_passes_at_14_00` (the v1 regression
      scenario explicitly pinned)
    - `todays_morning_cron_row_passes_at_23_59`
    - `yesterdays_row_throws_on_primary_check`
    - `todays_rate_date_with_absurdly_old_fetched_at_throws`
    - `fresh_fetched_max_hours_uses_config_value_not_hardcoded`
    - `misconfigured_threshold_clamps_to_one_hour`
    - `empty_daily_exchange_rates_table_throws`
    - `manual_source_today_passes`
    - `manual_source_yesterday_still_throws` (defensive — pins
      uniform source treatment)
    - `bot_session_refuses_on_yesterdays_row` (end-to-end through
      `BotPaymentService`)
    - `bot_session_passes_guard_on_todays_morning_cron_row`
    - `mixed_currency_admin_path_also_refuses_on_yesterdays_row`
      (end-to-end through `RecordMixedCurrencySplitFromAdminAction`)

  Time-of-day fixtures use `Carbon::setTestNow()` so behavior is
  deterministic regardless of the test-runner's wall clock.

- arch-lint clean (`new-P0=0 new-P1=0 P2=0`).
- pint clean for new lines.
- Existing `SplitPaymentTest` (5 cases, 21 assertions) still passes
  — no regression.
- Code-reviewer (deep mode, money path): _to be run on the v2 branch
  before deploy_.

**Reporting impact (worth knowing):** `daily_exchange_rates` rows
with `rate_date < today` will now refuse cashier sessions
explicitly. Existing reports / dashboards that read FX rate via
`DailyExchangeRate::today()` already had this assumption — now the
cashier path matches.

**No DB schema change.** No migration. No data mutation. Beds24
webhook, scheduler, bot menu UI, parent sum-lock logic on splits —
all untouched.

**Tracked follow-up #1 status:** SHIPPED in v2 on this commit.
Tracked follow-ups #2 (cron-failure alert) and #3 (fallback
sanity-band) remain in the planning entry below — to be tackled in
separate branches per the recommended sequencing.

**Backup reference:** Not applicable (no schema or bulk-data
change).

**Commit hash:** `7aaea25f0f79d4854c9963fa5b6aa185d1d2fd98`
(squash-merge to main, deployed 2026-05-10 09:31:45 +0200, 5/5 health
checks passed)

**Post-deploy real-time verification:** `daily_exchange_rates` row id=43
(rate_date=2026-05-10, fetched_at=2026-05-10T07:00:07+05:00, age=5h)
— this is the **exact scenario v1 broke**. The dry-run guard call
returned `PASS: guard does NOT throw — today's row is fresh.` Zero
StaleFxRateException events from real cashier traffic since deploy.

**Branch:** `fix/fx-staleness-guard-v2` (deleted post-merge)

---

## 2026-05-08 — FX pipeline review: 3 tracked follow-ups (#1 NOW SUPERSEDED BY 2026-05-10)

**Type:** Tracked follow-up plan, NOT a shipped fix. Listed here so future
sessions don't re-discover the same risks.

**Origin:** read-only deep-mode code-reviewer pass over `ExchangeRateService`,
`CalculateAndPushDailyPaymentOptions`, `DailyExchangeRate`, and the FX-read
side of `BotPaymentService` (2026-05-08). Verdict: APPROVE WITH RISKS — no
active money-moving bug today, but three latent gaps in the FX ingestion +
persistence pipeline. Each below is a future PR; convert to a "fixed" entry
when shipped (newest-on-top, this entry stays as a back-reference).

**Common discipline for all three:**
- Money-sensitive code → feature branch + code-reviewer in deep mode
  (`ai-coding-safety-policy.md` §2 high-risk tier).
- External HTTP must live in `app/Services/*Client.php` per CLAUDE.md hard
  line. New work should rename `ExchangeRateService` into `CbuClient` +
  `OpenErApiClient` + `FloatratesClient` + a thin orchestrator OR document
  the deviation. Not in any of the 3 follow-ups below — but the right time
  to land that rename is alongside follow-up #3 since it's the largest
  diff to that file.
- Real-sample testing (`feedback_no_mass_assign_for_system_state` +
  `ai-coding-safety-policy.md` §8): no mocked-perfect-input-only tests
  for parsers / integrations; use sanitized real CBU JSON fixtures where
  possible.
- Tests required for each item (gap categories listed per item).

### Follow-up #1 — Enforce `fx.stale_after_hours` consumer-side (HIGHEST PRIORITY)

**Problem:** `config/fx.php:56` defines `stale_after_hours=4` but **no code
reads it.** If the 07:00 cron fails on day N, cashier sessions on day N
silently keep using the day-N-1 `daily_exchange_rates` row indefinitely
(within the 6h Redis cache window, then re-fetches on cache miss but
still falls back to the latest persisted row via
`FxSyncService:88-106`). Operator-visible only via a `Log::warning`.
Removes the entire "morning cron failed silently → cashier uses stale
rate" failure mode.

**Desired behavior:**
- At payment-session preparation time
  (`BotPaymentService::preparePayment`) AND at any other consumer that
  resolves a frozen FX presentation, refuse to continue if the latest
  usable `daily_exchange_rates.fetched_at` is older than `now() -
  config('fx.stale_after_hours') hours`.
- Hard error with clear operational message: e.g.
  `StaleFxRateException("FX rate from {fetched_at} is X hours old; max
  allowed Y. Refresh via 'fx:push-payment-options' or set manual rate
  in Filament.")`.
- Bot user-facing reply (Russian): "Курсы валют устарели. Свяжитесь с
  менеджером." Same wording as the existing FX-presentation-unavailable
  block at `CashierBotController:1681-1683`.
- Filament admin's "Send Mixed-currency journal" parallel path: same
  refusal.

**Architecture (no code yet):**
- New exception: `app/Exceptions/Fx/StaleFxRateException.php` extending
  `\RuntimeException`. Caught at the controller boundary, surfaces a
  clean operator message; logged at WARNING (not ERROR — staleness is
  expected after a cron failure, ERROR is reserved for unexpected).
- Single canonical staleness check in `FxSyncService` or a new tiny
  helper `FxStalenessGuard` (Action). Called from
  `BotPaymentService::preparePayment` and from
  `RecordMixedCurrencySplitFromAdminAction::execute`. CLAUDE.md
  hard-line: no duplicated business rule → one place.
- The check reads `daily_exchange_rates` ordered by `rate_date desc`,
  takes the most recent row, compares its `fetched_at` against
  `now()->subHours(config('fx.stale_after_hours'))`.

**Scope (files likely touched):**
- `app/Exceptions/Fx/StaleFxRateException.php` (new)
- `app/Services/FxSyncService.php` (or new
  `app/Services/Fx/FxStalenessGuard.php`)
- `app/Services/BotPaymentService.php` (call the guard at session
  prep)
- `app/Http/Controllers/CashierBotController.php` (catch + user
  message)
- `app/Actions/Cashier/RecordMixedCurrencySplitFromAdminAction.php`
  (call the guard before `preparePayment`)
- `tests/Feature/Cashier/StaleFxRateGuardTest.php` (new)

**Tests required:**
- `bot_session_refuses_to_open_when_latest_row_older_than_threshold`
- `bot_session_opens_normally_when_row_is_fresh`
- `staleness_threshold_uses_config_value_not_hardcoded`
  (operator escape hatch: change env, re-clear config:cache, refused
  thresholds shift accordingly)
- `mixed_currency_admin_path_also_refuses_on_stale_row`
  (parity with bot path)
- `staleness_check_skips_for_rows_with_source_manual_AND_fetched_at_within_threshold`
  (manual override is the operator's escape valve and must be honored
  if recent enough)

**Rollout:**
- Branch: `feat/fx-staleness-guard`
- Reviewer: code-reviewer DEEP mode (money path).
- Deploy: separate from #2 and #3 so each can be reverted independently.

**Estimated diff:** ~80–120 LOC + ~150 LOC of tests.

---

### Follow-up #2 — Alert ops on `fx:push-payment-options` cron failure

**Problem:** `app/Console/Kernel.php:117` only logs `error` when the
07:00 morning cron returns FAILURE. `OwnerAlertService` is NOT wired
into `->onFailure`. Operations team finds out only when a cashier
sees the wrong rate — too late. Per `ai-coding-safety-policy.md` §9,
high-risk-tier failures must alert.

**Desired behavior:**
- When `fx:push-payment-options` exits with FAILURE (any source for
  USD failed → all three fallbacks failed → command aborts), fire a
  Telegram alert to the owner/ops group via `OwnerAlertService` with:
  - Command name: `fx:push-payment-options`
  - Timestamp (Asia/Tashkent)
  - Environment: `production`
  - Short error reason if available (last `Log::error`'s
    structured `error_class` + `error_message` from the catch block)
  - Action hint: "Check CBU connectivity, then re-run manually:
    `php artisan fx:push-payment-options`"

**Architecture (no code yet):**
- The scheduler `->onFailure(fn () => …)` callback is the right hook
  per Laravel's documented pattern.
- `OwnerAlertService::sendOpsAlert` already exists (used by
  `Beds24WebhookController::alertViolation`). Reuse — no new
  service.
- Capture the last error context via a small in-process file marker
  (e.g. `storage/app/fx/last_failure.json`) the command writes on
  abort. The `onFailure` callback reads it. Avoids needing the
  scheduler to dig into Laravel log files. If the marker is missing
  / stale, fall back to a generic message.
- Alternative shape: emit a `FxPushPaymentOptionsFailed` event from
  the command's failure path; subscribe via a listener that calls
  `OwnerAlertService`. More moving parts but cleaner. Decide at
  implementation time.

**Scope (files likely touched):**
- `app/Console/Commands/CalculateAndPushDailyPaymentOptions.php`
  (write failure marker on abort paths)
- `app/Console/Kernel.php` (`->onFailure` callback)
- `app/Services/OwnerAlertService.php` (likely just reuse — no new
  method needed)
- `tests/Feature/Cashier/FxPushPaymentOptionsAlertTest.php` (new)

**Tests required:**
- `cron_failure_fires_owner_alert_with_command_name_and_timestamp`
  (mock `OwnerAlertService`, assert `sendOpsAlert` called once with
  expected payload shape)
- `cron_success_does_not_fire_owner_alert`
- `alert_includes_short_error_reason_when_marker_present`
- `alert_uses_generic_message_when_marker_absent_or_corrupted`
  (defensive)
- `repeated_failures_in_one_day_each_fire_one_alert`
  (no de-duplication unless explicitly designed — operators want
  every retry attempt's failure to ping)

**Rollout:**
- Branch: `feat/fx-cron-failure-alert`
- Reviewer: code-reviewer cheap mode (alert wiring only, no money
  path touched).
- Deploy: independently of #1 and #3.

**Estimated diff:** ~40–60 LOC + ~100 LOC of tests.

---

### Follow-up #3 — Sanity-band validation for fallback FX rates

**Problem:** `ExchangeRateService::getUsdToUzs()` accepts whatever the
first non-null source returns — no ±X% bound vs yesterday's persisted
row, no cross-source agreement check. If `open.er-api.com` /
`floatrates.com` returns a glitched value (stale by weeks, swapped
with another currency, response-shape change), the morning cron
writes that bad rate to `daily_exchange_rates` and powers the cashier
bot all day. The per-payment variance guard at
`config/fx.php:22` (`manager_threshold_pct=10`) is a *per-payment*
check against the day's row — it does NOT catch a day where the
day's row itself is wrong. Most likely on a CBU-down day, since CBU
is rarely wrong.

**Desired behavior:**
- After the 3-source fallback chain returns a non-null rate, compare
  it against the most-recent persisted `daily_exchange_rates` row
  (any source, any date — typically yesterday's).
- If `|new_rate - last_rate| / last_rate > config('fx.fallback_sanity_band_pct')`
  (default e.g. **5%**), REJECT the fetched rate and return null
  (fall through to the next source).
- If ALL three sources are rejected by the sanity band → command
  exits FAILURE → triggers the alert path from follow-up #2.
- Operator escape valve: Filament admin can write a `source='manual'`
  row at any time. Manual rows are exempt from the sanity-band check
  (operator chose to override).
- For the very first run ever (no prior persisted row), skip the
  sanity check and accept whatever was fetched.

**Architecture (no code yet):**
- Threshold lives in `config/fx.php` as
  `'fallback_sanity_band_pct' => env('FX_FALLBACK_SANITY_BAND_PCT', 5.0)`.
  Distinct from `tolerance_pct` (per-payment variance) and
  `manager_threshold_pct` (per-payment block). Document the three
  distinct purposes in the config docblock.
- Sanity check goes in `ExchangeRateService` itself, between the
  fallback chain and the cache-write. Not in the morning command —
  the rule applies to ANY caller of `getUsdToUzs()` etc., including
  ad-hoc `ExchangeRateService::refresh()` from the admin panel.
- Cross-currency: each currency has its own threshold check against
  the same currency's prior row.

**Scope (files likely touched):**
- `app/Services/ExchangeRateService.php` (sanity-check method,
  rate-history lookup; or extract to `app/Services/Fx/FxSanityBand`
  if the diff bloats the file beyond the existing arch-lint baseline
  for it)
- `config/fx.php` (new threshold)
- `tests/Unit/Fx/ExchangeRateServiceFallbackTest.php` (new — replaces
  the missing fallback-chain test gap noted in the review)
- `tests/Unit/Fx/FxSanityBandTest.php` (new)

**Tests required (this is the big test gap from the review):**
- **Fallback chain** (long-overdue baseline):
  - `cbu_success_returns_cbu_rate` (no fallback hit; assert source='cbu')
  - `cbu_failure_falls_through_to_open_er_api`
  - `cbu_and_open_er_api_failure_falls_through_to_floatrates`
  - `all_three_sources_failure_returns_null`
  - `cross_rate_math_correct_for_rub_via_open_er_api`
    (regression guard for the "cross-derived RUB-via-USD" finding in
    the review §1)
- **Sanity band**:
  - `fetched_rate_within_band_accepted` (e.g. 12100 vs prior 12000 → 0.83% < 5% → accepted)
  - `fetched_rate_above_band_rejected` (e.g. 12700 vs prior 12000 → 5.83% > 5% → rejected, source returns null, chain continues)
  - `all_sources_rejected_by_sanity_band_returns_null` (cron then
    aborts, alert fires per #2)
  - `manual_source_rows_exempt_from_sanity_band`
    (operator override path stays open even if their value is far off)
  - `first_ever_run_skips_sanity_check_returns_fetched_rate`
    (cold-start case — no persisted row yet)
- **Real CBU sample fixture**: at least one test loads a sanitized
  real CBU JSON response (saved as `tests/Fixtures/Cbu/usd.json`) and
  asserts the parser extracts the correct rate. Per
  `ai-coding-safety-policy.md` §8 — protects against the GYG/Viator
  pattern where regex/parser changes broke on real-world inputs the
  synthetic tests didn't cover.

**Rollout:**
- Branch: `feat/fx-fallback-sanity-band`
- Reviewer: code-reviewer DEEP mode (money path; introduces new
  rejection logic).
- Deploy AFTER #1 and #2 — the sanity-band rejection only becomes
  observable to ops if both the staleness-guard (so the bot refuses
  on rejection-induced stale data) AND the cron-failure alert (so
  ops are paged when all sources reject) are already in place.
- Day-1 monitoring: watch the
  `Log::warning('ExchangeRateService: fallback rejected by sanity
  band')` rate. If it fires in normal-day traffic, the threshold is
  too tight; raise to 7% or 10% and re-deploy.

**Estimated diff:** ~80–100 LOC of service code + ~250 LOC of tests
(mostly fixtures + table-driven cases).

---

### Recommended sequencing

| Order | Item | Branch | Why this order |
|---|---|---|---|
| 1 | Staleness guard | `feat/fx-staleness-guard` | Highest leverage; closes the entire silent-stale-rate failure class. |
| 2 | Cron failure alert | `feat/fx-cron-failure-alert` | Independent + cheap; raises operational visibility before #3 lands. |
| 3 | Sanity-band validation | `feat/fx-fallback-sanity-band` | Largest + needs #1 and #2 in place to be fully observable. Also the right time to land the `*Client.php` rename if scope allows. |

Each branch is its own deploy with explicit user approval per
`local-first-delivery-flow`. Squash-merge to main, deploy via
`scripts/deploy-production.sh <sha>`. Convert each tracked entry above
to a real "shipped fix" entry in this log (with deployed SHA + post-deploy
verification notes) when it lands.

**No code change in this entry — planning only.**

---

## 2026-05-08 — Cashier split-payment broken end-to-end (two bugs)

**Symptom:** Operator tried to record booking $65 USD as split
520,000 UZS cash + 270,000 UZS card. Bot displayed
`🚫 Оплата заблокирована. Эскалируйте вопрос руководству.` —
exactly when sum-lock should have passed (520k + 270k = 790k UZS,
matching the FX-presented total).

Production audit: **0 successful splits in the prior 30 days, 43
single-method payments in the same window**. Operators were
working around it via single-method recording or by embedding
amounts into `beds24_bookings.guest_name` (real example: row had
`guest_name = "Tatyana Kreskas 800 000"`).

**Root cause (two cooperating bugs):**

1. **Per-leg variance evaluation in `recordPayment()`.**
   `BotPaymentService::recordSplitPayment()` correctly enforced
   sum-lock at the parent layer
   (`abs(sum - presented) <= 1.0`). It then called `recordPayment()`
   per leg. Inside `recordPayment()` the override-policy evaluator
   was unconditionally comparing each leg against the FULL presented
   booking total. For a 520k/270k split of 790k presented, each leg
   read as 34% / 66% variance → `OverrideTier::Blocked`
   (`fx.manager_threshold_pct = 10`) → `PaymentBlockedException`.
   Same bug affected `recordMixedCurrencySplitPayment` and
   `recordBulkGroupPayment` because both also delegate per-leg to
   `recordPayment()`.

2. **Silent `$fillable` failure on `CashTransaction` (co-conspirator).**
   `journal_entry_id`, `payment_group_type`,
   `base_currency_for_split`, and `journal_status` were absent from
   `CashTransaction::$fillable`. Laravel mass-assignment silently
   dropped them on `recordPayment()`'s `create()` call. The cash
   leg of a split persisted with `journal_entry_id = NULL`, then
   the card leg's duplicate-payment guard saw the cash row as a
   "prior unrelated payment" because the journal-id exemption
   couldn't match. Result: even with bug #1 fixed, the second leg
   would still fail with `DuplicatePaymentException`. Same silent-
   fillable pattern previously documented in
   `feedback_no_mass_assign_for_system_state`.

**Fix applied:**

- `BotPaymentService::recordPayment()` — when
  `paymentGroupType ∈ {split, mixed_currency_split, group_bulk}`,
  skip the override-policy evaluator and use a new
  `OverrideEvaluation::skippedForSplit()` factory yielding
  `tier=None / withinTolerance=true / variancePct=0`. The parent
  layer is the right authority for sum-lock at this granularity:

    - `recordSplitPayment`              → `abs(sum - presented) <= 1.0`
    - `recordMixedCurrencySplitPayment` → tiered variance against
                                          `expected_in_base`, hard
                                          5% ceiling
    - `recordBulkGroupPayment`          → distributed shares vs
                                          entered total

  Standalone payments (`paymentGroupType = null`) are UNCHANGED.
  The canonical evaluator still runs and `PaymentBlockedException`
  still fires for >10% variance.

- `CashTransaction::$fillable` — added the four journal-entry
  fields with an inline comment pointing at this incident so a
  future developer can't silently re-introduce the silent-drop
  pattern.

- `OverrideEvaluation::skippedForSplit()` — new factory documented
  explicitly as the split-leg / group-bulk audit path, returning
  honest audit columns rather than pretending an evaluator pass.

**Reporting impact (worth knowing):** split-leg rows now have
`within_tolerance=true / override_tier='none' / variance_pct=0`
just like clean standalone payments. Reports that filter for
"clean payments only" must add `WHERE payment_group_type IS NULL`
to exclude split legs. Distinct journal_entry_id / payment_group_type
columns are the canonical way to identify split rows.

**Verification:**

- 5 / 5 new tests pass on VPS test DB
  (`tests/Feature/Cashier/SplitPaymentTest.php`):

    - `realistic_uzs_split_succeeds_without_blocking` — the
      2026-05-08 Tatyana Kreskas scenario; pre-fix throws
      `PaymentBlockedException`, post-fix records 2 rows.
    - `failing_sum_lock_throws_at_parent_not_payment_blocked` —
      sum 700k vs presented 790k throws `\InvalidArgumentException`
      at the parent, NOT `PaymentBlockedException`. Proves bypass
      isn't too permissive.
    - `split_legs_share_journal_uuid_and_payment_group_type` —
      both legs share `journal_entry_id` and `payment_group_type='split'`,
      audit columns honestly record bypass.
    - `standalone_over_variance_payment_still_blocks` — 49%
      under-payment with `paymentGroupType=null` still throws
      `PaymentBlockedException`. Standalone gate intact.
    - `standalone_payment_with_same_amount_as_leg_still_blocks` —
      520k UZS standalone (same dollar value as a successful split
      leg) still blocks. Proves bypass keys on context, not amount.

- arch-lint clean (`new-P0=0  new-P1=0  P2=0`).
- pint clean for new lines.
- Code-reviewer (deep mode, money path): APPROVE, no blockers.
- Existing tests preserved: `BotPaymentServiceOverrideTest::canonical_evaluator_is_injected`,
  `MixedCurrencySplitPaymentTest` parent sum-lock cases.

**Tracked follow-ups (not in this PR):**

- `recordSplitPayment` hardcodes `1.0` absolute tolerance whereas
  sister methods use `sumLockTolerance($cur)` (100 UZS / 0.50
  USD/EUR). Consistent for UZS today; should align if helper widens.
- `withMixedJournalContext()` stamps `paymentGroupType: 'split'`
  for mixed-currency splits, so the `'mixed_currency_split'` arm
  of the bypass list is currently dead code (still works because
  the `'split'` arm covers it). Future cleanup: either remove
  the dead string or stamp `'mixed_currency_split'` consistently.
- Add a `recordMixedCurrencySplitPayment` end-to-end DB test
  (existing test partial-mocks `recordPayment`, so the bypass
  contract for that path isn't directly pinned).

**No DB schema change.** No migration. No data mutation. Beds24,
scheduler, bot menu, cashier flow UI all untouched. Pure logic +
fillable change inside `recordPayment()` and `CashTransaction`.

**Backup reference:** Not applicable.

**Commit hash:** `ea9bbea0119695aea96e7db62acec5118a80a634`
(squash-merge to main, deployed 2026-05-08 10:04 +0200, 5/5 health
checks passed)
**Branch:** `fix/cashier-split-leg-variance-bypass` (deleted post-merge)

---

## 2026-05-07 — Day-1 internal feedback request: auto-cron disabled, operator-button replacement

**Symptom:** At 10:00 +0500 the cron `tour:send-review-requests`
fired and sent the Day-1 internal feedback message
(`Hi {name} 😊 Hope the trip was a good one… ⭐ /feedback/{token}`)
to every confirmed inquiry whose tour ended yesterday. Operators kept
getting surprised by sends they didn't trigger because in practice
the message wording was indistinguishable from the public-review
push that Phase 1.7.0 (2026-05-05) had already manualised.
Concrete example: Karl Marton, INQ-2026-000087, sent 2026-05-06
10:00:04 — operator hadn't agreed to it.

**Root cause:** Phase 1.7.0 split post-tour messages into two
systems and made only one (TripAdvisor public review) manual. The
internal feedback cron (`tour:send-review-requests`) was kept on
schedule in `app/Console/Kernel.php:145–148` based on the assumption
that "internal feedback" was reputation-safe enough to auto-fire.
Operationally that distinction was lost: operators read both as
"the system is sending review nudges without my approval".

**Fix applied:** Symmetric with the 2026-05-05 TripAdvisor
manualization. `tour:send-review-requests` removed from the
scheduler in `app/Console/Kernel.php`. New
`SendManualInternalFeedbackRequestAction` is the single source of
truth for the per-inquiry send (token creation, WhatsApp + email
fallback, orphan `TourFeedback` cleanup on full failure,
stamp-on-success-only contract via `forceFill`). The legacy CLI
(`tour:send-review-requests`) is intentionally retained but
refactored to delegate to the Action — CLAUDE.md hard line "no
duplicated business rule" satisfied; CLI shrinks 195→109 lines.
A second header action `💬 Send Internal Feedback Request` (color
warning, icon chat-bubble) lives next to the existing
`🌟 Send TripAdvisor Review Request` on the Filament
`BookingInquiryResource` view page. Same `canSendReviewRequest`
eligibility predicate (super_admin/admin/manager + STATUS_CONFIRMED
+ cancelled_at IS NULL + phone-or-email present), same modal-on-
resend pattern with prior-send timestamp warning, distinct visuals
so operators can't conflate the two.

**Verification:**
- 5 / 5 new tests pass on VPS test DB:
  `message_contains_internal_feedback_url_on_our_domain`,
  `successful_whatsapp_send_stamps_feedback_request_sent_at`,
  `both_channels_failing_does_not_stamp_and_cleans_orphan_feedback`,
  `whatsapp_failure_does_not_stamp_when_email_also_missing`,
  `auto_cron_is_no_longer_scheduled` (regression guard symmetric
  with the existing public-review pin in
  `ManualTripAdvisorReviewRequestTest`).
- `php artisan schedule:list` post-deploy: `tour:send-review-requests`
  ABSENT (target), `tour:send-public-review-reminders` ABSENT
  (kept manual from before), three unrelated reminder crons
  (`tour:send-reminders`, `tour:send-late-guest-reminders`,
  `tour:send-hotel-requests`) PRESENT untouched.
- Code-reviewer (deep mode): APPROVE, no blockers. One tracked
  medium risk — double-click during slow WA send can produce a
  duplicate `TourFeedback` row + duplicate send (same risk profile
  as the already-shipped TripAdvisor button; future mitigation via
  `Cache::lock("feedback-send:{$inquiry->id}", 30)` around the
  Filament dispatch methods).
- arch-lint clean (`new-P0=0  new-P1=0  P2=0`).
- Pint clean for new lines.
- Live operator smoke test: pending (operator will click the new
  button on a confirmed non-cancelled inquiry).

**No DB schema change.** No migration. No data mutation. Beds24,
cashier, and bot code untouched.

**Backup reference:** Not applicable.

**Commit hash:** `57477b2507d46c5e5282ff89a748d9c1b1a1e558`
(squash-merge to main, deployed 2026-05-07 08:05 +0200, 5/5 health
checks passed)
**Branch:** `feat/manual-internal-feedback-request` (deleted post-merge)

---

## 2026-05-06 — Hotel-booking bot avail check fragile to DeepSeek outages

**Symptom:** At 14:14 +0500 the operator sent `9-11 may avail` to
`@j_booking_hotel_bot`. DeepSeek API timed out (cURL error 28, 30s)
and the bot replied with the generic `"I couldn't parse that. Try one
of: bookings today / arrivals today / cancel 12345 / Type /help…"`.
The rest of the bot kept working (cancel, view-bookings, search,
/pay) — only avail was hit.

**Root cause:** `LocalIntentParser` had no matcher for
`check_availability`. Cancel, show, view-bookings, arrivals,
departures, and search all had local regex; availability was the lone
intent that always fell through to DeepSeek with `path:"llm"`. So
every avail query — the hotel's daily-driver question — was a hard
dependency on a remote LLM, with the bot's "graceful fallback" being
the same generic menu hint regardless of how parseable the input was.

**Fix applied:** Added `matchAvailRange()` to
`app/Services/BookingBot/LocalIntentParser.php` covering the
daily-driver shapes:

```
<range> avail | <range> availability
avail <range> | availability <range>
today avail   | tomorrow avail
avail today   | availability tomorrow
ISO single + ISO range with avail keyword
```

Single-date inputs auto-extend `check_out` by 1 day so Beds24 sees a
1-night stay rather than 0 nights. `today`/`tomorrow` are resolved
inline as 1-night stays starting on that day. Multi-day ranges
(e.g. `9-10 may` = 1 night, `21-28 may` = 7 nights) flow through
`DateRangeParser` unchanged. Returned shape matches DeepSeek's
existing `check_availability` contract so `CheckAvailabilityAction`
needed no change. Unparseable shapes (`avail nonsense`,
`cancel booking #5 avail`) still fall through to DeepSeek — the LLM
fallback path is preserved.

**Verification:**
- 66 / 66 targeted tests pass on VPS test DB (130 assertions),
  including 9 dataProvider rows covering every documented pattern,
  11 semantic tests pinning date correctness, and one integration
  test in `BookingIntentParserTest::test_avail_local_hit_bypasses_deepseek`
  that mocks `DeepSeekIntentParser` with `shouldNotReceive('parse')`
  for `9-10 may avail` — proves the daily-driver shape is now
  LLM-independent.
- arch-lint clean (`new-P0=0  new-P1=0  P2=0`).
- Pint clean for new lines.
- Manual Telegram smoke test pending operator verification — expect
  `path:"local"` on `9-10 may avail`, `today avail`, `tomorrow avail`,
  `availability 21-28 may`.

**No DB schema change.** No migration. No external HTTP added. No
data mutated. Pure regex + DateRangeParser delegation.

**Backup reference:** Not applicable.

**Commit hash:** `14b9937729c1fef4f324cf868eece70f97ba0289`
(squash-merge to main, deployed 2026-05-06 20:10 +0200, 5/5 health
checks passed)
**Branch:** `feat/local-avail-parser` (deleted post-merge)

---

## 2026-05-06 — Cashier petty-sale silently excluded from drawer balance

**Symptom:** Doniyor recorded a $10 incoming petty sale through the
cashier Telegram bot. The bot replied "✅ Продажа записана" and the
row landed in `cash_transactions` (id=488), but the displayed drawer
balance never moved. Two earlier rows on the same code path had the
same issue (id=483, 485 = 240 000 UZS each on shift 393).

**Root cause:** `RecordSmallSaleAction::execute()` did not set
`source_trigger` on the `CashTransaction::create([...])` payload.
The DB column default `'beds24_external'` (introduced by migration
`2026_03_29_100002_add_fx_columns_to_cash_transactions_table.php` to
retro-classify pre-existing rows) silently took effect. The
`drawerTruth()` scope used by `BalanceCalculator::getBal()` excludes
`beds24_external` rows because they are audit-only mirrors of Beds24
webhook payments — so legitimate cashier-bot petty-sale rows got
silently filtered out of every running shift balance. Sibling write
paths (`CashierExpenseService`, `CashierExchangeService`,
`CashierBotController` deposit flow) all set `source_trigger`
explicitly; `RecordSmallSaleAction` was the lone outlier introduced
in Phase 1.6.0 as the unified Filament + bot recorder.

**Fix applied:** `RecordSmallSaleAction::execute()` now takes a
`CashTransactionSource $sourceTrigger` parameter (default
`CashierBot` — the bot path is the highest-volume call site) and
inserts that value on every row. Filament admin call site passes
`ManualAdmin` explicitly. `Beds24External` is rejected at the API
boundary so future callers cannot re-introduce the same silent
drawer exclusion. Four regression tests in
`tests/Feature/Cashier/RecordSmallSaleTest.php` pin the contract,
including a test that exercises Doniyor's exact scenario through
`BalanceCalculator::getBal()`. `BotSmallSaleFlowTest` extended to
assert `source_trigger=cashier_bot` on the controller path.

**Historical data NOT mutated** per the append-only ledger doctrine.
The three impacted rows stay tagged `beds24_external` and remain
invisible to drawer balance. Shifts 393 and 394 are already closed;
their close audit trails are preserved as-is. Total invisible:
240 000 UZS + 10 USD across three rows.

| row id | shift | amount | currency | created_by | created_at |
|---|---|---|---|---|---|
| 483 | 393 | 120 000 | UZS | 1318 | 2026-05-05 23:12 |
| 485 | 393 | 120 000 | UZS | 1318 | 2026-05-05 23:16 |
| 488 | 394 | 10 | USD | 798 (Doniyor) | 2026-05-06 09:04 |

**No DB schema change.** No migration. Beds24 webhook code untouched.

**Backup reference:** Not applicable — this fix did not run any
schema or bulk-data change.

**Commit hash:** `fdc5361153c8f313d5a62898e7f59899a456c783` (squash-merge to main, deployed 2026-05-06 17:19 +0500, 5/5 health checks passed)
**Branch:** `fix/cashier-petty-sale-source-trigger` (deleted post-merge)

---

## 2026-05-05 — Driver create 500: NOT NULL fuel_type without form `->required()`

**Symptom:** `/admin/drivers/create` returned HTTP 500 on submit. Log:
`SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'fuel_type' cannot be null`.

**Root cause:** `drivers.fuel_type` is `varchar(255) NOT NULL` (no default)
but the Filament `Select::make('fuel_type')` was never marked
`->required()`. Operator submits without picking → form passes `null`
to INSERT → DB rejects with 1048.

**Fix applied:** Added `->required()->default('propane')` to the Select
in `app/Filament/Resources/DriverResource.php:93`. `propane` matches
the existing-row convention so the common-case flow is one tap less.

**Schema cleanup (deferred):** `fuel_type` is operator metadata
(reimbursement-side), not operational truth — should be nullable.
Filed as a separate ticket; this entry only contains the prod-blocker
fix without a migration.

**DB touched?** No. Code-only fix.

**Commit:** `f842135` (deploy 5/5).

---

## 2026-05-04 — Feedback submit 500: TypeError on rating cast

**Symptom:** Guest Ruyi (and 1+ earlier) hit HTTP 500 when submitting the
post-tour feedback form with stars selected. 11 errors logged across two
days (9 of them in a 10-minute Ruyi retry burst). Comments-only
submissions silently succeeded; star ratings were lost.

**Root cause:** `StoreFeedbackRequest::keepTagsIfLow(?int $rating)` is
strict-typed. Laravel's `integer` validation rule validates but does
not cast, so `validated()` returned ratings as strings (`"5"`), tripping
a `TypeError` in `toFeedbackData()` before the controller ran.

**Fix applied:** Added `prepareForValidation()` to
`app/Http/Requests/StoreFeedbackRequest.php` which coerces the four
rating fields (`driver_rating`, `guide_rating`, `accommodation_rating`,
`overall_rating`) to int (or null for empty) before rules execute.
Strict-types contract preserved end-to-end.

**Tests:** 5-case feature spec at
`tests/Feature/StoreFeedbackRequestTest.php` pinning string→int
coercion, low-rating tag retention, high-rating tag drop, comments-only
path, and empty-string→null behaviour.

**Verified:** End-to-end POST against live URL with stars now returns
HTTP 200 and renders the thank-you page; row persists with
`gettype() === 'integer'` for all four rating columns.

**DB touched?** No. Code-only fix. No backup needed.

**Commit:** `8e2b60b` (deploy `5/5` health checks).

---

## 2026-05-02 — Cashier bot payment quick-pick: any arrival date

**Operator pain:** the payment list only showed Beds24 arrivals on
today. Late payments (guest paid day after check-in) and advance
bookings (deposit before arrival) required typing the booking ID
manually. Operators couldn't see who they were paying for, no quick
visual scan.

**Note:** the manual booking-ID fallback already works for any date
(line 353, `hPayRoom`). The fix is UX-only — surfaces what was already
possible.

**Fix applied:**

1. `fetchInHouseGuests($from, $to)` accepts an arrival-date range and
   passes `arrivalFrom` / `arrivalTo` to Beds24 instead of the single
   `arrival` parameter.
2. New `renderGuestList()` helper builds the inline keyboard with
   per-row paid-status badge: `#84213317 | KEISUKE NOZAKI | 02.05 ✅`
   when a `cashier_bot` payment already exists for the booking.
   Operators see duplicates before tapping.
3. Sort order: today first → upcoming ascending → recent past
   descending (matches operator mental model: "today first, then
   upcoming, then recent missed").
4. Default range: `-3 days back / +14 days forward` (env-configurable
   via `CASHIER_PAYMENT_ARRIVAL_DAYS_BACK` / `_FORWARD`).
5. Date-jump shortcut row at bottom of every list:
   `⬅️ Вчера   📅 Сегодня   Завтра ➡️`
   Plus `📅 Другая дата` → enters new `payment_arrival_date` state.
6. Free-text date parser (`parseFlexibleDate`) accepts:
   - ISO `2026-04-28`
   - `28.04`, `28/04`, `28.04.26`, `28/04/2026`
   - Russian: `вчера` / `сегодня` / `завтра`
   - English: `yesterday` / `today` / `tomorrow`
   - Strict validation rejects month=13, day=32, Feb 30 etc — Carbon's
     silent overflow ("32.13" → Feb 1 next year) is blocked. Same
     financial-integrity rule that drives the currency parser.
7. Cancelled / declined bookings remain excluded (status quo).

**Files changed:**
- `app/Http/Controllers/CashierBotController.php` — refactored
  `startPayment`, new `renderGuestList`, expanded `fetchInHouseGuests`,
  new `pickArrivalDate` callback handler, new `hPaymentArrivalDate`
  state handler, new `parseFlexibleDate` + `buildDateStrict` helpers.
- `app/Services/CashierBot/CashierBotCallbackRouter.php` —
  registered `pick_date_*` callback prefix.
- `config/services.php` — two new env keys for range defaults.

**Tests:** 8 unit tests in
`tests/Unit/CashierBot/PaymentDateRangeTest.php` covering ISO, DD.MM,
DD/MM, short-year, Russian and English relative terms, garbage
rejection, and Carbon-overflow rejection. Green on isolated VPS test
DB. Beds24 API integration not unit-tested — covered by live-system
verification on the next paid booking.

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-payment-date-range_20260502_144326.sql.gz`
**Commit:** `b13a921` (squash of `feature/payment-list-any-date`).
**Risk:** low — no DB schema, no service-layer change, no payment
logic change. Only the quick-pick list rendering. Manual booking-ID
fallback unchanged.

---

## 2026-05-02 — Expense approval gate is opt-in; default OFF (straight thru)

**Operator confusion:** Aziz recorded a 210 EUR expense (id=25) on
shift #385 today (the first working EUR expense after the parser
fix landed). Owner received "💸 Расход на одобрение" approval-request
ping. The expense was already recorded against the shift and already
deducting from the EUR drawer balance — the "approval" only stamps
`approved_at`/`rejected_at` on `cash_expenses`, it does NOT block,
reverse, or modify the amount. So the ping was a sign-off prompt,
not a real hold-until-approved gate. Operator-facing semantics were
"already happened, just acknowledge" — confusing.

**Fix:** new master switch `CASHIER_EXPENSE_APPROVAL` (default false).
Disabled by default — expenses go straight through with no owner
ping regardless of amount. To re-enable owner pings above per-
currency thresholds, set `CASHIER_EXPENSE_APPROVAL=true` in env.
USD/EUR/RUB thresholds also made env-configurable
(`EXPENSE_APPROVAL_THRESHOLD_USD/EUR/RUB`); UZS already was via
`EXPENSE_APPROVAL_THRESHOLD`.

Existing `approve_expense_<id>` / `reject_expense_<id>` callbacks
remain wired so any already-sent pings can be resolved.

**Tests:** 2 unit tests in `tests/Unit/CashierBot/ExpenseApprovalGateTest.php`
covering gate-disabled (no approval for any currency/amount) and
gate-enabled (per-currency threshold respect). Green on isolated
VPS test DB.

**Cleanup:** dangling expense #25 auto-stamped approved post-deploy
since the new policy is "no approval gate". No re-record needed.

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-expense-gate_20260502_143256.sql.gz`
**Commit:** `83a0163` (squash of `fix/disable-expense-approval-gate`).

---

## 2026-05-02 — cash:audit-daily anomaly audit + 07:00 schedule

**Why:** today's four cashier-domain fixes (drawer truth, duplicate
message, currency parser, owner alerts) restored correctness. The
audit is the durable surface that surfaces regressions of those
fixes, plus operational drift, going forward.

**What:** new artisan command `cash:audit-daily {--date=}` runs every
morning at 07:00 Tashkent (after the 23:00 daily cash report has
fired). It audits the day that just closed and dispatches a single
PASS/WARN/ALERT Telegram summary to the owner channel via
OwnerAlertService. Replayable for any past date.

Anomaly checks (v1):

| # | Check | Severity |
|---|---|---|
| 1 | Drawer-truth leak (card/transfer rows in drawer scope) — regression of `4ae201d` | ALERT |
| 2 | Exchange rows mixed into income (daily-report Gap from earlier audit) | WARN |
| 3 | Open shifts at end of yesterday — forgotten close | WARN |
| 4 | FX rates older than 7 days | WARN |
| 5 | FX rates older than 14 days — manager-tier escalation will misfire | ALERT |
| 6 | Beds24 push failures (`booking_fx_syncs.push_status != pushed`) | ALERT |
| 7 | UZS expenses with foreign-currency keywords — possible silent mis-record (parser hardened in `bd0bafd`) | WARN |
| 8 | Unexpected categories on cashier_bot rows | WARN |

Exit codes: 0 PASS, 1 WARN, 2 ALERT — meaningful for cron-driven
escalation.

**Schedule wiring:** `app/Console/Kernel.php` — uses Laravel's
existing `schedule:run` system-cron tick (already running every
minute). No new cron entries needed. `withoutOverlapping()` set.

**Tests:** 5 unit tests in
`tests/Unit/CashierBot/CashierDailyAuditCommandTest.php` covering
PASS, WARN-on-exchange, WARN-on-open-shift, ALERT-on-FX-stale, and
no-leak-on-card paths. All green on isolated VPS test DB.

**Live smoke test (post-deploy, against today's data):**
`php artisan cash:audit-daily --date=2026-05-02` →
"Severity: WARN (exit 1)" → summary delivered to owner Telegram.
WARN expected because today included exchange transactions that
the daily report mixes into income (Gap pending Phase 2 cleanup).

**Backup:** `/var/backups/databases/daily/jahongirnewapp_pre-cashier-daily-audit_20260502_141613.sql.gz`
**Commit:** `ff1fbf5` (squash of `feature/cashier-daily-audit`).

**Phase 2 follow-up:** anomaly thresholds and check list will likely
expand based on what tomorrow's first scheduled run surfaces. Each
new check is one method on the command — easy to extend without
schema or service changes.

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
