# L-001 EXECUTION PLAN — Resolve duplicate `Schema::create` migrations

**Ticket:** L-001 (P0 blocker) · **Generated:** 2026-04-18 · **Commit baseline:** `dc6c812`
**Investigator output:** based on live VPS introspection + code reading.

> This document turns a medium-uncertainty ticket into a **1-file change**.
> The original ticket scoped consolidation migrations for 3 tables. **Actual reality: 1 small fix for 1 migration. The other 2 pairs are already self-healing.**

---

## 1. What I found on production

### 1.1 Row counts (from `php artisan db:show --counts`)

```
guest_payments ........... 38 rows
booking_fx_syncs ......... 247 rows
fx_manager_approvals ..... 0 rows
booking_inquiries ........ 47 rows
bookings ................. 0 rows   ← legacy aggregate is EMPTY
cash_transactions ........ 28 rows  ← whole ledger is small
```

### 1.2 Migration history on production (from `migrations` table)

| id | migration | batch | status |
|---:|---|---:|---|
| 120 | `2025_03_04_033652_create_guest_payments_table` | 4 | ✅ ran |
| 216 | `2026_03_28_130000_create_booking_fx_syncs_table` | 4 | ✅ ran |
| 217 | `2026_03_28_130001_create_fx_manager_approvals_table` | 4 | ✅ ran |
| 219 | `2026_03_29_100001_create_booking_fx_syncs_table` | 4 | ✅ ran |
| 221 | `2026_03_29_100003_create_fx_manager_approvals_table` | 4 | ✅ ran |
| 225 | `2026_04_06_100000_add_daily_exchange_rate_id_to_booking_fx_syncs` | 6 | ✅ ran |
| 366 | `2026_04_17_000002_create_guest_payments_table` | 37 | ✅ ran |
| 367 | `2026_04_17_000003_backfill_guest_payments_from_paid_inquiries` | 38 | ✅ ran |

### 1.3 Live schema (authoritative) — matches the v2 migrations in each pair

All three tables on production carry their **v2** schema (plus any later ALTERs). The v1 migration rows in `migrations` are historical artefacts — someone manually dropped the v1 `guest_payments` table in April 2026 before running the v2 migration in the same batch.

### 1.4 Code inspection — are the v2 migrations `migrate:fresh`-safe?

| Table | v2 migration | `dropIfExists` present? | Safe on `migrate:fresh`? |
|---|---|---|---|
| `guest_payments` | `2026_04_17_000002` | ❌ **NO** | 🔴 **FAILS** — v1 runs first, v2 throws "table already exists" |
| `booking_fx_syncs` | `2026_03_29_100001` | ✅ yes, with `SET FOREIGN_KEY_CHECKS=0` | 🟢 safe |
| `fx_manager_approvals` | `2026_03_29_100003` | ✅ yes, with FK re-add on `cash_transactions` | 🟢 safe |

---

## 2. Verdict

**L-001 is a 1-line fix.**

Only `guest_payments` is a real `migrate:fresh` blocker. The two FX tables already have proper `dropIfExists` handling — leaving the v1 migration files in place is cosmetic debt, not functional risk.

The original L-001 ticket over-scoped because I had not yet inspected the v2 file contents. Correcting scope now.

---

## 3. Revised scope (what actually changes)

### Change 1 (required) — add `Schema::dropIfExists` to the v2 `guest_payments` migration

**File:** `database/migrations/2026_04_17_000002_create_guest_payments_table.php`
**Current `up()`:**
```php
public function up(): void
{
    Schema::create('guest_payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('booking_inquiry_id')
            ->constrained('booking_inquiries')->cascadeOnDelete();
        // ...
    });
}
```

**Change:**
```php
public function up(): void
{
    // Legacy v1 migration (2025_03_04_033652) creates guest_payments
    // with a different shape (guest_id + booking_id). On fresh installs
    // v1 runs first; without this drop v2 would throw.
    // Production was manually reconciled in April 2026; this comment
    // makes the intent explicit for future maintainers.
    Schema::dropIfExists('guest_payments');

    Schema::create('guest_payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('booking_inquiry_id')
            ->constrained('booking_inquiries')->cascadeOnDelete();
        // ... unchanged
    });
}
```

**Why this is safe for production:**
- Production has v2 schema in `migrations` table row 366 (batch 37). Laravel **does not re-run already-run migrations**. Deploying the fix changes the *file* but Laravel won't re-execute the migration. `guest_payments` data is preserved.
- `migrate:fresh` drops all tables first anyway — the `dropIfExists` in v2 is belt-and-braces after the earlier v1 `Schema::dropIfExists` on its rollback.

**Why this is safe for `migrate:fresh`:**
- v1 runs → creates legacy shape
- v2 runs → **now** drops v1 + creates v2 shape → success
- v2's backfill (`2026_04_17_000003`) runs → populates from `booking_inquiries`, idempotent via `->exists()` check

### Change 2 (recommended) — add explanatory header to v1 `guest_payments` migration

**File:** `database/migrations/2025_03_04_033652_create_guest_payments_table.php`

Add a class-level docblock so a future maintainer understands why the v1 file exists despite being superseded:

```php
/**
 * DEPRECATED (superseded by 2026_04_17_000002_create_guest_payments_table).
 *
 * This original schema (guest_id + booking_id + payment_status) was
 * replaced in April 2026 by a booking_inquiry_id-based design.
 * File kept in repo because it was deployed to production (batch 4).
 * Do NOT delete — the `migrations` table references this migration name.
 * On fresh installs v2 runs after v1 and replaces the table via dropIfExists.
 */
```

### Change 3 (optional) — `MIGRATION_HISTORY.md` entry

**New file:** `docs/architecture/MIGRATION_HISTORY.md`

Record the decision so this investigation doesn't have to be repeated.

```markdown
# Migration history — audit notes

## 2026-04-18 — L-001: duplicate Schema::create migrations resolved

Three tables had duplicate `Schema::create` migrations. Investigation
(see docs/architecture/L-001_execution_plan.md) showed:

- **guest_payments**: v1 (2025_03_04) superseded by v2 (2026_04_17).
  Fixed v2 to include `Schema::dropIfExists` so `migrate:fresh` works.
- **booking_fx_syncs**: v2 (2026_03_29) already self-healing via
  `SET FOREIGN_KEY_CHECKS=0 + dropIfExists + recreate`. No change.
- **fx_manager_approvals**: v2 (2026_03_29) self-healing via
  `dropIfExists + recreate + FK re-add`. No change.

All three tables on production hold their v2 schema. Row counts at
baseline: guest_payments=38, booking_fx_syncs=247, fx_manager_approvals=0.
```

---

## 4. What's NOT changed

- **v1 migration files are NOT deleted.** They're referenced by the production `migrations` table (rows 120, 216, 217). Deletion would break `php artisan migrate:status` and risks unintended re-execution on rollback. They remain as historical artefacts, documented.
- **`booking_fx_syncs` v2 migration is NOT modified.** It already handles the re-create correctly (with FK disable during drop). 247 rows on production preserved.
- **`fx_manager_approvals` v2 migration is NOT modified.** Already self-healing. 0 rows means zero data risk.
- **No DB changes on production.** The fix affects only `migrate:fresh` on fresh installs / staging resets.

---

## 5. Test plan

### 5.1 Local — `migrate:fresh` on a clean MySQL

**Goal:** prove the fix makes `migrate:fresh` green.

```bash
# Starting state: broken repo (before fix)
cd ~/projects/jahongirnewapp
git log -1 --oneline
# Drop local DB; recreate
mysql -e 'DROP DATABASE IF EXISTS jahongirnewapp_test; CREATE DATABASE jahongirnewapp_test;'
# Set .env.testing or equivalent to point at jahongirnewapp_test
php artisan migrate:fresh --env=testing   # EXPECTED TO FAIL on v2 guest_payments
```

**Apply the fix.**

```bash
php artisan migrate:fresh --env=testing   # EXPECTED TO SUCCEED
php artisan db:show --counts --env=testing | grep -E 'guest_payments|booking_fx_syncs|fx_manager_approvals'
# Expected:
#   guest_payments    0 rows
#   booking_fx_syncs  0 rows
#   fx_manager_approvals 0 rows
```

### 5.2 Staging — apply fix, run full test suite

```bash
# On staging server
cd /var/www/jahongirnewapp-staging
git pull origin main
php artisan test --testsuite=Feature   # must pass
```

**No migration runs on staging** because the v2 migration is already in staging's `migrations` table — Laravel skips it.

### 5.3 Production — deploy, verify no-op

```bash
# Production deploy (existing deploy.sh flow)
ssh jahongir
cd /var/www/jahongirnewapp
git pull origin main
# Check migration status BEFORE migrate
php artisan migrate:status | grep -E 'guest_payments|booking_fx_syncs|fx_manager_approvals'
# All rows must already show "Ran"
php artisan migrate:status --pending
# Expected output: "Nothing to migrate"
# Row counts unchanged
php artisan db:show --counts | grep -E 'guest_payments|booking_fx_syncs|fx_manager_approvals'
```

**Acceptance criteria:**
- Local `migrate:fresh` now runs clean end-to-end
- Staging `php artisan test` passes
- Production `migrate:status --pending` shows nothing to migrate after pull
- Production row counts unchanged for all 3 tables

---

## 6. Deploy procedure (safe)

1. **Branch off main:**
   ```bash
   cd ~/projects/jahongirnewapp
   git checkout -b fix/L-001-guest-payments-dropifexists
   ```
2. Apply Change 1 (code edit) and Change 2 (docblock) and Change 3 (history doc)
3. **Local test:** run §5.1.
4. Commit + push branch (or direct-to-main per project's `feedback_jahongirnewapp_git_workflow.md` since this is docs+migration-header, no runtime code path change).
5. **Review diff carefully** — it must be a single added line in one migration + docblock + new doc file. If the diff contains anything else, abort.
6. Merge to `main`.
7. Deploy per project's `deploy.sh`. Deployment must be **idempotent** — the fix is a no-op on the production DB because Laravel skips already-ran migrations.
8. Post-deploy verification (§5.3).

---

## 7. Rollback

If anything goes wrong (unlikely — the change is additive to a dormant code path):

```bash
# On main, one commit back
git revert <commit-sha>
git push origin main
# Re-deploy
```

**Rollback is instant.** No DB state depends on the change.

---

## 8. Risk assessment — revised

| Original ticket said | Actual |
|---|---|
| Risk: **HIGH** | Risk: **LOW** |
| Effort: M (2–3d) | Effort: **S (≤½ day)** — 1 code line + 1 docblock + 1 history entry + test runs |
| Data transformation required | No data transformation. `migrate:fresh` is the only affected path. |
| Requires staging DB snapshot | Staging needs zero DB work — test suite is sufficient |
| Production backup mandatory | Production unaffected — change is dormant on existing DB |

---

## 9. What this changes for downstream tickets

- **L-002** (BotPaymentService dedupe) can start **immediately after L-001 merges** — no 2–3 day waiting period.
- **Total P0 effort drops from ~10 days to ~5 days** (L-001 shrinks from M to S).
- The overall `REFACTOR_PLAN.md §8` totals should be updated once L-001 completes; the revised effort is ~**7–9 weeks** (down from 8–10) in realistic parallel execution.

---

## 10. Open question before execution

The fix is small but touches a migration file that already ran in production. There are two philosophically-valid options:

| Option | Pros | Cons |
|---|---|---|
| **A — edit the existing v2 file** (recommended, documented above) | Single source of truth; `migrate:fresh` fixed | Modifying a migration file that has already run on production — some teams treat that as "never modify". Here it's safe because Laravel won't re-run it, and the only consumer of the file content is `migrate:fresh` on fresh installs. |
| **B — add a new "consolidation" migration** (no existing file edit) | Preserves the "migrations are immutable after running" convention | Doesn't actually fix the original failing file; a new migration can't prevent v1+v2 collision on a fresh install because both v1 and v2 will still run before the consolidation migration |

**Recommendation: Option A.** The migration is being fixed for future `migrate:fresh` runs only; existing history is unaffected. Option B doesn't solve the problem.

If you prefer Option B regardless (team convention), the alternative would be:
- Leave v2 file untouched
- Add new migration `{stamp}_ensure_guest_payments_v2_schema.php` that uses `Schema::hasColumn(...)` to detect v1 shape and `Schema::rename()` or `Schema::drop() + Schema::create()` to land on v2
- This is larger, more testable, but solves nothing production doesn't already have

Confirm A or B before I proceed.

---

## 11. Summary — one sentence

**L-001 is a 1-line change in one migration file that makes `php artisan migrate:fresh` succeed on a clean install; production is unaffected; the other two "duplicate" tables handle themselves; downstream tickets can start immediately after the ≤½-day fix lands.**

---

**Status: awaiting user go-ahead on Option A or B. No code written yet.**
