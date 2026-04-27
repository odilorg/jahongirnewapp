# FX simplification plan

**Status:** plan-only (2026-04-27).
**Goal:** replace the 4-tier override + manager-approval machinery with a single threshold-guarded model. Keep audit trail and a hard upper bound; remove every other layer.

---

## 0. The problem in one paragraph

The cashier bot currently runs every FX-sensitive payment through:
`OverridePolicyEvaluator → OverrideTier (None/Cashier/Manager/Blocked) → FxManagerApprovalService → ManagerApprovalRequiredException`.
The Manager tier throws an exception with no resolver upstream, so any payment whose deviation lands in the 2–10% band fails outright (A2 bug). Below that, the cashier-tier path works but every payment still drags through DTO snapshots, FX sync rows, and tier evaluation. ~600–900 LOC of policy machinery for a problem that, in this business's actual cash-flow reality, is solved by **"record the rate the cashier used + flag if it's far from reference + block only catastrophic typos."**

---

## 1. The new model (one rule, two thresholds)

```
deviation = abs(actual_rate - reference_rate) / reference_rate

deviation == 0%        → record silently, was_overridden = false
0% < deviation ≤ 3%    → record silently, was_overridden = true
3% < deviation ≤ 15%   → require non-empty override_reason, record, was_overridden = true
deviation > 15%        → REJECT (validation error in form + InvalidFxOverrideException at service)
```

**Threshold rationale (locked 2026-04-27 review):**
- 3% silent band is generous enough for normal market jitter without producing operator-friction noise.
- 15% reject threshold is the "this isn't a mistake, it's a different rate entirely" line — anything past it is almost certainly a typo or a malicious entry.
- The previously-considered 5% / 25% defaults were too permissive — 25% rejects almost nothing.

**Locked vocabulary** (do not invent new terms during implementation):
- `reference_rate` — system-fetched rate (CBU / Beds24 / cached fallback).
- `actual_rate` — what the cashier types (may equal reference_rate).
- `was_overridden` — boolean, true iff actual ≠ reference.
- `override_reason` — nullable string; required iff deviation > 3%.
- `deviation_pct` — **stored** as `DECIMAL(7,4)` (signed, so a regression below reference reads as negative). Stored not derived because:
  - admin Filament filters need to sort on it without recomputation
  - reports stay consistent if the reference-rate source changes meaning later
  - cheap to write once at the save guard, expensive to recompute on every list query

**Thresholds** are config values, not code constants:

```
config('cashier.fx.override_reason_required_pct',  3.0)
config('cashier.fx.hard_block_pct',               15.0)
```

So a future ops decision to widen 3→4% is a one-line config change, no migration, no code edit.

---

## 2. Files inventory — what's there today

### Live FX system (everything in scope)

```
app/Services/Fx/OverridePolicyEvaluator.php       ← evaluates the 4 tiers
app/Services/Fx/FxManagerApprovalService.php      ← persistence for pending approvals
app/Services/Fx/FxSyncService.php                 ← per-booking FX sync rows
app/Services/Fx/PrintPreparationService.php       ← presentation prep
app/Services/Fx/Beds24PaymentSyncService.php      ← unrelated, leave alone

app/DTOs/Fx/PaymentPresentation.php               ← frozen quote DTO
app/DTOs/Fx/OverrideEvaluation.php                ← tier-evaluation result

app/Enums/OverrideTier.php                        ← None/Cashier/Manager/Blocked
app/Exceptions/ManagerApprovalRequiredException.php  ← thrown on Manager tier (= the A2 bug)

app/Models/FxManagerApproval.php                  ← table fx_manager_approvals
app/Models/BookingFxSync.php                      ← table booking_fx_syncs

app/Console/Commands/ExpireManagerApprovals.php   ← cron sweep for pending approvals
app/Console/Commands/RepairMissingFxSyncs.php     ← reconciliation
app/Jobs/FxSyncJob.php                            ← per-payment FX sync dispatch
```

### Suspected duplicates / dead copies (Phase 0 must confirm)

```
app/Services/FxManagerApprovalService.php         ← same name as app/Services/Fx/...
app/Services/FxSyncService.php                    ← same name as app/Services/Fx/...
app/Services/OverridePolicyEvaluator.php          ← same name as app/Services/Fx/...
app/DTO/PaymentPresentation.php                   ← same name as app/DTOs/Fx/...
app/DTO/RecordPaymentData.php                     ← may pair with the DTO/ copy
```

These two locations co-exist. Either a partial migration was abandoned, or both are imported in different places. **Phase 0 must determine which copy each consumer imports** before any deletion. If the older `app/Services/*.php` copies are dead, they go away with the rest of the tier machinery.

### Consumers (callers we'll touch or leave alone)

```
app/Http/Controllers/CashierBotController.php     ← payment flow router (touch)
app/Services/BotPaymentService.php                ← orchestration entry-point (rewrite)
app/Actions/Ledger/Adapters/CashierPaymentAdapter.php  ← reads PaymentPresentation (touch)
app/Models/CashTransaction.php                    ← gains 4 columns (touch)
app/Models/LedgerEntry.php                        ← reads FX fields (touch lightly)
app/DTOs/Ledger/LedgerEntryInput.php              ← read shape (touch lightly)
```

---

## 3. The 3 phases

Each phase is independently shippable, independently revertable, and leaves the system in a working state.

### Phase 0 — Audit (read-only, no commit-of-record)

Outcome: a one-page note appended at the bottom of this doc identifying which of the duplicated `app/Services/*` vs `app/Services/Fx/*` files are live.

Steps:
1. `grep -rn "use App\\Services\\OverridePolicyEvaluator"` vs `"use App\\Services\\Fx\\OverridePolicyEvaluator"` across `app/`, `tests/`, `config/`, `routes/`. Tally consumers.
2. Same for the other 4 duplicates (`FxManagerApprovalService`, `FxSyncService`, `PaymentPresentation`, `RecordPaymentData`).
3. Same for `OverrideTier`, `FxManagerApproval`, `ManagerApprovalRequiredException` — should be one canonical location each.
4. Verify which `BotPaymentService` constructor binding wins in the Laravel container.

**Stop condition:** if either the live or dead set is unclear, surface and ask before proceeding.

**No deploy. No PR.** This is reconnaissance.

---

### Phase 1 — Add new columns + dual-write (backward-compatible)

Outcome: every NEW `CashTransaction` row carries the new fields. OLD code path (tier system) still runs and writes its own fields. Nothing breaks; nothing is read from the new fields yet.

Schema migration (additive only):

```sql
ALTER TABLE cash_transactions
  ADD COLUMN reference_rate    DECIMAL(14,4) NULL,
  ADD COLUMN actual_rate       DECIMAL(14,4) NULL,
  ADD COLUMN was_overridden    BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN override_reason   VARCHAR(500) NULL,
  ADD COLUMN deviation_pct     DECIMAL(7,4) NULL,         -- signed; (actual−reference)/reference × 100
  ADD INDEX cash_tx_was_overridden_idx (was_overridden, deviation_pct);
```

All 5 columns are nullable / defaulted so historical rows aren't violated. The composite index supports the admin "show overridden, sorted by deviation" Filament filter directly.

Code change:
- Add a new method on `BotPaymentService` (e.g. `recordPaymentSimple()`) that:
  - takes `(amount, currency, reference_rate, actual_rate, override_reason)`,
  - computes `deviation_pct`,
  - validates against the 5%/25% thresholds (throws `App\Exceptions\Fx\InvalidFxOverrideException` on >25% or missing reason),
  - writes `CashTransaction` with the new 4 columns populated,
  - **also** still populates the old fields (frozen-DTO equivalents) so existing readers (admin panels, ledger projections) keep working.
- The Filament `UtilityUsage` form / Cashier bot form is NOT changed in this phase — old flow still runs.

DB-backup discipline applies (additive migration but it's still schema). Phase 1 ships with a fresh full DB backup.

**Tests:** new `FxThresholdGuardTest` — 5 cases (using locked thresholds 3% / 15%):
1. exact reference rate → was_overridden=false, no reason needed, deviation_pct=0
2. 2% deviation, no reason → silent record, was_overridden=true, deviation_pct=2.0000
3. 8% deviation, no reason → throws InvalidFxOverrideException("override_reason required")
4. 8% deviation, with reason → records with reason, deviation_pct=8.0000
5. 20% deviation, with reason → throws InvalidFxOverrideException("hard block")

**Deploy gates:** clean DB backup, migration tested on a VPS snapshot, no code path reads new columns yet.

---

### Phase 2 — Switch the cashier bot to the simple flow

Outcome: cashier bot's payment path uses `recordPaymentSimple()`. Old tier machinery stops being executed for new payments but is still on disk.

Code change:
- In `CashierBotController`'s payment-confirm dispatch, route to `recordPaymentSimple()` instead of the existing `BotPaymentService::recordPayment()` tier-evaluating path.
- Filament `UtilityUsageResource` form and the cashier bot form acquire:
  - reference rate display (read-only, with a "tap to accept" affordance),
  - actual rate input,
  - override-reason textarea (visible iff deviation > 5%, required iff visible),
  - hard-block validation if deviation > 25% (form-level + service-level both).
- `Manager` tier branch in the old code path becomes unreachable for new payments. The exception can't fire from the new code path.

**Cashier R2 Phase 11 (payment extraction)** becomes feasible after this phase — payment is no longer the special snowflake.

Code stays on disk for one cycle so historical reads / pending FxManagerApproval rows can drain naturally. (Verify there are zero pending rows on prod first; based on current logs there aren't.)

**Tests:** end-to-end happy-path payment + 8% override-with-reason + 30% rejection. Existing cashier feature tests must stay green.

**Backwards compatibility:** any reader of `CashTransaction` that previously dereferenced `override_tier` / `presentation_*` JSON keeps working because Phase 1 dual-writes them. After Phase 2 lands, those fields stop being populated on new rows but historical rows are intact.

---

### Phase 3 — Delete the dead code

Outcome: tier system, manager-approval flow, and presentation DTOs are gone. ~600–900 LOC removed.

Drops:
- `app/Services/Fx/OverridePolicyEvaluator.php`
- `app/Services/Fx/FxManagerApprovalService.php`
- `app/Services/Fx/PrintPreparationService.php` (only if unused after Phase 2 — confirm)
- `app/DTOs/Fx/PaymentPresentation.php`
- `app/DTOs/Fx/OverrideEvaluation.php`
- `app/Enums/OverrideTier.php`
- `app/Exceptions/ManagerApprovalRequiredException.php`
- `app/Models/FxManagerApproval.php` + migration to drop `fx_manager_approvals` table (DB backup required)
- `app/Console/Commands/ExpireManagerApprovals.php`
- All duplicate `app/Services/Fx*Service.php` and `app/Services/Override*.php` and `app/DTO/PaymentPresentation.php` per Phase 0 audit.
- The old `BotPaymentService::recordPayment()` (rename `recordPaymentSimple()` → `recordPayment()` once the dust settles).
- Any `cash_transactions` columns whose only writer was the old path (e.g. `override_tier`, `presentation_*`) can be dropped — but **drop them in a follow-up Phase 3b** with its own backup, so Phase 3's code change can be reverted without a DB rollback.

**Tests:** delete the now-irrelevant tier tests. Keep / extend the threshold-guard tests.

**A2 ticket closes itself** here — the Manager tier no longer exists.

---

## 4. Schema diff — final shape after Phase 3b

```diff
  cash_transactions
+   reference_rate    DECIMAL(14,4) NULL
+   actual_rate       DECIMAL(14,4) NULL
+   was_overridden    BOOLEAN NOT NULL DEFAULT FALSE
+   override_reason   VARCHAR(500) NULL
+   deviation_pct     DECIMAL(7,4) NULL                 (signed)
+   INDEX (was_overridden, deviation_pct)
-   override_tier             ← drop in 3b
-   presentation_uzs_amount   ← drop in 3b
-   presentation_eur_amount   ← drop in 3b
-   presentation_rub_amount   ← drop in 3b
-   presentation_rate_*       ← drop in 3b (whichever exist)
-   fx_manager_approval_id    ← drop in 3b
-   booking_fx_sync_id        ← keep if Beds24 sync still needs it; verify in 3b

  fx_manager_approvals        ← drop entire table in 3b
```

Phase 0's audit will produce the exact column list to drop; this section is a placeholder until then.

---

## 5. What stays on the floor (intentionally untouched)

- **`Beds24PaymentSyncService` / `Beds24PaymentSyncJob`.** These push a recorded payment to Beds24's API. They consume `CashTransaction` data; not part of the FX evaluation policy and not part of this simplification.
- **`BookingFxSync` / `FxSyncJob` / `RepairMissingFxSyncs`.** These maintain a per-booking FX-rate snapshot used by the receipt/print system. Independent of the tier policy. Audit in Phase 0 to confirm; if confirmed independent, leave alone.
- **`ExchangeRateService`.** The reference-rate fetcher. The new flow still calls it for `reference_rate`.
- **All cashier-bot intents already extracted (R2 Phases 1–6).**
- **All meter / pokazaniya code.**

---

## 6. Operational visibility (replaces the lost approval friction)

The threshold guard provides the floor. Above that, oversight is **post-hoc and ambient**:

1. **Filament admin filter** on `CashTransaction`: `was_overridden = true` toggle, sortable by `deviation_pct` (computed on the fly: `(actual_rate − reference_rate) / reference_rate`).
2. **Daily / weekly digest email or Telegram message** summarising overridden payments by cashier, with reason. Implementation: existing `OwnerAlertService` + a small `OverridePaymentDigest` command on the scheduler. Not in Phase 1–3 scope; queue for after.
3. **Auto-flag** in admin if `deviation_pct` > 15% (between "needs reason" and "hard block"). Visual flag only, no enforcement.

These three together are stronger oversight than the broken Manager-approval flow currently provides — because the Manager flow doesn't actually fire (A2).

---

## 7. Risk register

| Risk | Mitigation |
|---|---|
| Phase 1 dual-write doubles FX serialization cost | Confirm with one prod transaction profile pre-merge; Phase 2 removes the duplication anyway |
| Two-copy file confusion (Phase 0) reveals one path is dead but actually has live consumers | Phase 0 stop-condition: surface and ask, do not delete blind |
| Hard-block 25% rejects legitimate edge case (currency redenomination) | Threshold is config; bumping to 40% is a one-line change. Document in ops runbook. |
| Old `recordPayment()` callers in queue-stored serialized jobs | Rename in Phase 3, after queue drains. Phase 2 does NOT rename. |
| Lost ability to "force a payment to wait for owner" | Documented intentional trade-off; replaced by post-hoc digest |

---

## 8. What I'm asking you to approve

Just the **plan**. No code. No migration. No deploy.

Specifically:
1. **The model:** ≤5% silent / 5–25% require reason / >25% block, both thresholds in config.
2. **The 3-phase shape:** Phase 0 audit → Phase 1 dual-write → Phase 2 switch reader → Phase 3 delete (with 3b for DB column drops).
3. **The locked vocabulary:** `reference_rate`, `actual_rate`, `was_overridden`, `override_reason`. Don't invent new names later.
4. **The non-goals:** no change to Beds24 sync, BookingFxSync, ExchangeRateService, or any non-FX cashier intent.
5. **The replacement oversight:** Filament filter + digest report + 15% visual flag — to be built post-Phase-3, not before.

If approved, Phase 0 audit is the next concrete step (read-only, ~1 hour).

---

## 9. Open questions for you

1. **Confirm the thresholds** — is 5% / 25% right, or do you have lived experience suggesting different defaults? (They're config so it's reversible, but the defaults shipped in code should match your actual operating reality.)
2. **Is there *any* observed past abuse case** that the tier system actually caught — or is the manager tier's enforcement value entirely theoretical? Your answer tightens the "what stays" list.
3. **Phase 3b column drops** — do the Filament admin pages currently render the old `presentation_*` / `override_tier` columns anywhere users care about? If yes, those readers need to be retired before the columns go.

Answers can come one at a time as Phases run; only #1 needs to land before Phase 1 ships.
