# Phase 1.5 — Mixed-currency split payments

**Status:** 1.5.1 (Foundation) + Admin Manual Capability — IN PROGRESS as of 2026-05-04. Bot UX (1.5.2) deferred until demand justifies.

**Trigger:** Real blocked scenario 2026-05-04 — guest paid one booking with 500,000 UZS by card AND $50 USD in cash. Same-currency split (Phase 1) couldn't capture both legs cleanly. Operator was forced into either inaccurate single-method recording or fake same-currency entries.

---

## Doctrine

> **Commercial truth ≠ Settlement truth.**
> A booking has one commercial currency (what it's worth).
> A payment can have multiple settlement legs (how the guest actually paid).
> Each leg remains individually truthful. Never combine approximately.

> **When reality blocks finance truth, build infrastructure.**
> **When frequency blocks operator speed, build UX.**
> 1.5.1 is the first; 1.5.2 is the second.

---

## Architectural invariants (non-negotiable)

| # | Rule | Why |
|---|---|---|
| 1 | One frozen FX snapshot per journal | Both legs use the same rates from the same TTL window. No mid-session rate drift. |
| 2 | Each leg has its own currency, amount, frozen rate | The columns already exist on `cash_transactions`. Reuse, don't extend. |
| 3 | **Booking's "base currency" is the operator-selected presentation currency. NO override in v1.5** | Prevents "silent favorable manipulation" — operator can't pick a base that hides real FX variance. Hard-coded. |
| 4 | Sum-lock enforced IN THE BASE CURRENCY | Each leg → converted to base via its frozen rate → summed → must equal `presentedAmountFor(base)` ±tolerance. |
| 5 | Maximum 2 legs per journal | 3+ legs are operationally rare and balloon UX complexity. Manual handling for those rare cases. |
| 6 | Manager-tier elevation when one leg > 50% of total in non-base currency | High-value FX leg deserves a second pair of eyes. |
| 7 | Beds24 sync still happens per-leg in each leg's native currency | Don't aggregate into one Beds24 payment. Two legs = two sync rows. Beds24 already supports multi-method per booking. |
| 8 | Manager override reason is structured (enum), not free text | Categories: `fx_variance` / `guest_convenience` / `insufficient_local_cash` / `ota_mismatch` / `other`. |
| 9 | `journal_status` tracks lifecycle | `complete` / `pending_second_leg` / `voided` / `failed_sumlock`. Protects against orphan legs. |
| 10 | Live FX-rate API calls during recording are FORBIDDEN | Frozen presentation rates only. No mid-session refresh. Race-condition + audit-nightmare prevention. |

---

## Phasing

### Phase 1.5.1 — Foundation (~2h, ship now)

**Schema additions to `cash_transactions`:**
- `base_currency_for_split` (varchar 3, nullable) — the booking's commercial currency at recording time. NULL for non-split rows.
- `journal_status` (enum, default `complete`) — `complete` / `pending_second_leg` / `voided` / `failed_sumlock`.

**Service: `BotPaymentService::recordMixedCurrencySplitPayment(leg1, leg2, baseCurrency)`**
- Uses one frozen `PaymentPresentation` (caller's responsibility — both legs reference the same `presentationId` snapshot).
- Sum-lock in base currency via frozen rates.
- Generates one `journal_entry_id` UUID; both legs share it; both legs get `payment_group_type='split'`.
- `journal_status='complete'` on success; `failed_sumlock` exception path documented for future retry/log scrubbing.
- Manager-tier elevation enforced when single leg is > 50% of base total in a non-base currency.

**Tests (~12 cases):**
- Pass: UZS base + UZS card + USD cash with correct conversion
- Fail: sum-lock overpay → InvalidArgumentException
- Fail: sum-lock underpay → InvalidArgumentException
- Fail: stale presentation → StalePaymentSessionException
- Both legs share `journal_entry_id`, `base_currency_for_split`, `payment_group_type='split'`
- Each leg has own `usd_equivalent_paid` populated
- Each leg gets own `beds24_payment_sync` row
- High-FX-variance leg requires manager-tier override
- Same-currency split STILL routes through `recordSplitPayment` (not the mixed path) — old behaviour preserved
- Journal status lifecycle: complete on success, voided on rollback

### Admin Manual Mixed-Currency Journal Builder (~2h, ships with 1.5.1)

**Filament page or table action** — accessible to `super_admin` / `admin` / `manager` roles only.

**Form shape:**
1. Pick `BookingInquiry` or enter Beds24 booking ID
2. Bot loads frozen `PaymentPresentation` (or generates new from current rates if expired)
3. Operator picks first leg: currency + amount + method
4. Operator picks second leg: currency + amount + method (auto-suggested via frozen rate, can override)
5. UI shows live sum-lock validation (✅ / ⚠ / 🚨)
6. If high-FX-variance: structured reason picker
7. Submit → calls `recordMixedCurrencySplitPayment`

**Why admin-first instead of bot:**
- Solves today's real blocked scenario immediately
- Validates the architecture under real use without bot state-machine complexity
- Surfaces FX edge cases on a controlled surface
- Operators can use it as a fallback even after bot UX ships

### Phase 1.5.2 — Bot UX (deferred)

Trigger condition: ≥3 mixed-currency cases in any 2-week window after 1.5.1 ships.

State machine sketch in original plan; not built now. The admin path covers today's needs without bot complexity.

### Phase 1.5.3 — Governance + reporting (deferred)

- Daily report: explicit "Mixed-currency journals: N (Σ in base)" line.
- `cash:audit-daily` check #8: orphan legs, sum-lock failures, journal_status anomalies.
- Filament admin: read-only journal-entry viewer (expand journal_entry_id → see all legs).

---

## Demand-trigger thresholds (locked)

| Demand level (per 2 weeks) | Action |
|---|---|
| 0–1 mixed scenarios | Skip 1.5 entirely |
| 2–4 scenarios | Build 1.5.1 + Admin Manual |
| 5–14 scenarios | Build 1.5.1 + 1.5.2 (bot UX) |
| 15+ scenarios | Full 1.5 + 1.5.3 governance |

**As of 2026-05-04: 1 confirmed blocked scenario → triggers "Foundation + Admin Manual" track.**

Track future events via the daily Telegram report's "manual FX conversion" line (to be added in 1.5.3) or an ad-hoc operator note convention.

---

## Architectural red lines (NEVER cross)

- Mid-leg currency conversion ("convert my UZS to USD as I record") — out of scope, ever.
- Live FX-rate API call during recording — frozen presentation rates only.
- Mixed currencies on one row — one row, one currency, always. Two legs = two rows.
- More than 2 legs per journal in v1.5 — phase 2 question if it happens.
- Operator-chosen base currency — base = booking's presentation currency, hard-coded. No override.

---

## Hidden benefits (Phase 2.x foundations this enables)

The same `journal_entry_id` + `payment_group_type` + `journal_status` substrate later carries:
- Deposit + balance workflow (one journal, leg 1 = deposit, leg 2 = balance, completed when leg 2 lands)
- OTA correction journals (leg 1 = original, leg 2 = correction)
- Refund decomposition (refund split across original payment instruments)
- Airport-terminal vs local-cash hybrids
- Cross-shift reversal pairing
- True double-entry GL accounting (Phase 3+)

Building 1.5.1 cleanly = laying the foundation for all of the above without rewriting later.

---

## Validation gate before any deploy

**Beds24 per-leg sync must be confirmed not to aggregate or break** when receiving two payments in different currencies for the same booking. This is the highest-risk hidden breakage zone. Validate via:

1. Code review of `Beds24PaymentSyncJob` for currency-mixing assumptions
2. Sandbox test against Beds24 staging (if available) with a known dual-currency payment
3. Inspect `beds24_raw_data.invoiceItems` for already-extant multi-currency examples

If validation fails → Phase 1.5.1 ships WITHOUT Beds24 sync for mixed-currency rows; legs are flagged for manual reconciliation.

---

## Sign-off

**Plan approved:** 2026-05-04
**Doctrine codified by:** real-world blocked scenario, not speculation
**Next review:** after 5 real mixed-currency events OR if a new architectural requirement surfaces
