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
