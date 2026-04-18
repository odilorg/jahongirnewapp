# Migration history — audit notes

Decisions and anomalies about the `database/migrations/` folder that
maintainers need to know. Append-only.

---

## 2026-04-18 — L-001: duplicate `Schema::create` migrations resolved

Three tables had duplicate `Schema::create` migration files.
Investigation (`docs/architecture/L-001_execution_plan.md`) found:

### `guest_payments` — fixed

- **v1** (`2025_03_04_033652_create_guest_payments_table`) — original
  schema with `guest_id` + `booking_id` + `payment_status`. Ran in
  production on batch 4 (migrations id=120).
- **v2** (`2026_04_17_000002_create_guest_payments_table`) — replaced
  the schema with `booking_inquiry_id` + refund semantics (positive/
  negative amounts). Ran in production on batch 37 (migrations id=366).

**Anomaly:** v2 originally had no `Schema::dropIfExists`. Production
was manually reconciled (v1 table dropped by hand before v2 ran).
**On fresh installs `migrate:fresh` would fail** — v1 creates the
table, v2 then fails with "table already exists".

**Fix (L-001, 2026-04-18):**
1. Added `Schema::dropIfExists('guest_payments')` at the top of v2's
   `up()` method (with explanatory comment).
2. Added a DEPRECATED docblock to the v1 file explaining why it must
   not be deleted (referenced by production `migrations` table).
3. This change is safe in production because Laravel does not re-run
   already-run migrations. Affects only `migrate:fresh` on fresh
   installs / local dev / staging reset.

### `booking_fx_syncs` — no change needed

- **v1** (`2026_03_28_130000_create_booking_fx_syncs_table`) — initial
  schema.
- **v2** (`2026_03_29_100001_create_booking_fx_syncs_table`) — 24h
  later, replaced the schema.

v2 already includes `SET FOREIGN_KEY_CHECKS=0 + Schema::dropIfExists
+ recreate`, so `migrate:fresh` succeeds. Leaving both files in place.

### `fx_manager_approvals` — no change needed

- **v1** (`2026_03_28_130001_create_fx_manager_approvals_table`) —
  initial schema.
- **v2** (`2026_03_29_100003_create_fx_manager_approvals_table`) — 24h
  later, replaced the schema and re-adds the FK from `cash_transactions`.

v2 already includes `Schema::dropIfExists + recreate + FK re-add`, so
`migrate:fresh` succeeds. Leaving both files in place.

### `supplier_payments` — fixed

Discovered during L-001 verification on 2026-04-18 when the first
`migrate:fresh` attempt failed **after** the guest_payments fix. The
phase-1 inventory grep had not flagged this pair because the two
files are 20 months apart.

- **v1** (`2024_08_22_130240_create_supplier_payments_table`) —
  original tour-centric schema (`tour_booking_id`, `driver_id`,
  `guide_id`, `amount_paid`).
- **v2** (`2026_04_16_000010_create_supplier_payments_table`) —
  replaced with polymorphic `(supplier_type, supplier_id)` + nullable
  `booking_inquiry_id` design.

**Anomaly:** identical to the guest_payments case — v2's `up()`
originally had no `Schema::dropIfExists`, so `migrate:fresh` failed
after v1 created the table.

**Fix (L-001 extension, 2026-04-18):**
1. Added `Schema::dropIfExists('supplier_payments')` at the top of
   v2's `up()`.
2. Added a DEPRECATED docblock to v1.

### `telegram_pos_sessions` — no change needed

v2 (`2025_10_18_235845`) already includes `Schema::dropIfExists` in
`up()`.

### `room_statuses` — no change needed

v2 (`2026_03_11_000002_rebuild_housekeeping_tables`) already drops
before recreate.

### `jobs` — no change needed

v2 (`2026_03_12_220000_create_jobs_and_failed_jobs_tables`) uses
`if (! Schema::hasTable('jobs'))` guards for idempotency. Same pattern
for `job_batches`, `failed_jobs`.

### `bot_analytics` — no change needed

v2 (`2025_10_14_002118`) uses an early `if (Schema::hasTable('bot_analytics'))
return;` guard.

### Full audit — all 8 duplicate `Schema::create` pairs

| Pair | Protection in v2 | Status after L-001 |
|---|---|---|
| `booking_fx_syncs` | `SET FK=0` + `dropIfExists` | ✅ healthy |
| `fx_manager_approvals` | `dropIfExists` + FK re-add | ✅ healthy |
| `guest_payments` | `dropIfExists` (added by L-001) | ✅ healthy |
| `supplier_payments` | `dropIfExists` (added by L-001 extension) | ✅ healthy |
| `telegram_pos_sessions` | `dropIfExists` in up() | ✅ healthy |
| `room_statuses` | `dropIfExists` in up() | ✅ healthy |
| `jobs` | `hasTable` guard | ✅ healthy |
| `bot_analytics` | `hasTable` guard | ✅ healthy |

Phase 1 inventory (`docs/architecture/INVENTORY.md`) originally
listed only 3 broken pairs. Correct count is **8 duplicate pairs,
2 broken before L-001, 0 broken after**.

### Live row counts at the time of this change

| Table | Row count on production |
|---|---:|
| `guest_payments` | 38 |
| `booking_fx_syncs` | 247 |
| `fx_manager_approvals` | 0 |

### Pre-change backup

Full `jahongir` DB dump taken before the edit:

```
/var/backups/databases/daily/20260418_081212_jahongirnewapp_pre-L-001.sql.gz
md5: dda59135c9a64f6a7bd52926019c3cb2
```

### What to do if `migrate:fresh` ever fails on these tables again

1. Check the live schema matches the v2 migration file.
2. If it does, ensure v2 has `Schema::dropIfExists` at the top of `up()`.
3. Do not delete the v1 file — production `migrations` table still
   references it.

---

## Convention for future duplicate migrations

If you ever need to replace a table's schema (not alter), treat it as
a **`dropIfExists + create` pattern inside a NEW migration file with
a later timestamp**, and always include the `Schema::dropIfExists`
line. Never leave a v2 file that depends on the v1 table not existing.

Example:

```php
public function up(): void
{
    // Drop previous schema (created by 20XX_XX_XX_XXXXXX_...).
    Schema::dropIfExists('my_table');

    Schema::create('my_table', function (Blueprint $table) {
        // new shape
    });
}
```
