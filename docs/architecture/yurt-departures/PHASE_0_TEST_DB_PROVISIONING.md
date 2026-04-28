# Phase 0 — Test Database Provisioning Record

**Document type:** Provisioning provenance (operational artifact)
**Scope:** One-time setup of `jahongirnewapp_test` on Jahongir VPS for the platform-wide test infrastructure defined in `PHASE_0_ARCHITECTURE_LOCK.md` §12.

**Why this document exists:**
If a future test fails, this record lets you instantly distinguish *infrastructure issue* from *code issue*. No spelunking through MySQL history, no guessing whether grants drifted. Provisioning provenance is small effort now, gold later.

---

## 1. Pre-provisioning state

| Item | Value |
|---|---|
| Date | _<fill in: YYYY-MM-DD HH:MM TZ>_ |
| Operator (who ran the SQL) | _<fill in: name>_ |
| Host | Jahongir VPS (jahongir SSH alias) |
| MySQL version | _<fill in: `mysql --version` output>_ |
| Method used | _<fill in: Option 1 (sudo mysql) / Option 2 (debian.cnf) / Option 3 (admin user)>_ |

---

## 2. SQL executed

The exact SQL run during provisioning. Copy verbatim from your terminal session.

```sql
-- <PASTE THE EXACT SQL YOU RAN HERE>

-- Expected:
-- CREATE DATABASE IF NOT EXISTS jahongirnewapp_test
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--
-- GRANT ALL PRIVILEGES ON jahongirnewapp_test.* TO 'jahongirapp'@'localhost';
-- FLUSH PRIVILEGES;
```

---

## 3. CREATE DATABASE result

```
<paste output>

Expected:
Query OK, 1 row affected (X.XX sec)
```

---

## 4. GRANT result

```
<paste output>

Expected:
Query OK, 0 rows affected (X.XX sec)
```

---

## 5. FLUSH PRIVILEGES result

```
<paste output>

Expected:
Query OK, 0 rows affected (X.XX sec)
```

---

## 6. Verification queries (run as `jahongirapp` user, NOT privileged user)

This is the proof that the app user can use the test DB.

```sql
SHOW DATABASES LIKE 'jahongirnewapp_test';
```

```
<paste output>

Expected:
+--------------------------------+
| Database (jahongirnewapp_test) |
+--------------------------------+
| jahongirnewapp_test            |
+--------------------------------+
```

```sql
SHOW GRANTS FOR 'jahongirapp'@'localhost';
```

```
<paste output>

Expected at minimum:
GRANT ALL PRIVILEGES ON `jahongirnewapp_test`.* TO `jahongirapp`@`localhost`
```

```sql
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'jahongirnewapp_test';
```

```
<paste output>

Expected:
+----------------------------+------------------------+
| DEFAULT_CHARACTER_SET_NAME | DEFAULT_COLLATION_NAME |
+----------------------------+------------------------+
| utf8mb4                    | utf8mb4_unicode_ci     |
+----------------------------+------------------------+
```

```sql
USE jahongirnewapp_test;
SHOW TABLES;
```

```
<paste output>

Expected:
Empty set
```

---

## 7. Provisioning sign-off

- [ ] CREATE DATABASE succeeded
- [ ] GRANT succeeded
- [ ] FLUSH PRIVILEGES succeeded
- [ ] `jahongirnewapp_test` visible to `jahongirapp@localhost`
- [ ] Charset = `utf8mb4`, collation = `utf8mb4_unicode_ci`
- [ ] Grant scope is `jahongirnewapp_test.*` ONLY (no global `*.*` grants)
- [ ] `SHOW TABLES` returns empty set (clean slate)
- [ ] Production DB `jahongir` was NOT touched at any point

**Provisioned:** _<date/time>_ by _<operator>_

---

## 8. Post-provisioning hand-off

After this document is complete, the foundation verification protocol (PHASE_0 §12.3) executes:

1. Pull this prep commit into the isolated clone (`git pull` in `/var/test-runs/yurt-departures-phase-1`)
2. Copy `.env.testing.example` → `.env.testing`, fill DB_PASSWORD + APP_KEY
3. `php artisan config:clear --env=testing`
4. Triple verification (phpunit.xml / .env.testing / runtime DB connection)
5. `composer install --no-interaction --prefer-dist`
6. Run targeted suite: `php artisan test --filter='DepartureModelTest|SeatLockConcurrencyTest'`
7. Rollback rehearsal: `migrate:fresh → migrate:rollback → migrate`
8. Generate `PHASE_1_FOUNDATION_VERIFICATION.md`
9. Commit + push artifact
10. Telegram notification on success

---

## 9. Known failure modes (cross-reference)

If verification fails, consult PHASE_0 §12.7 "Known failure modes" first. Most common:

| Symptom | Likely cause |
|---|---|
| Triple verification shows `jahongir` instead of `jahongirnewapp_test` | Stale Laravel config cache; run `config:clear --env=testing` |
| `Access denied` for `jahongirapp` on test DB | GRANT didn't include the test DB scope; re-run GRANT |
| Migration fails with FK error | Test DB has stale rows; run `migrate:fresh --env=testing` |
| Charset query returns `latin1` | Test DB created without `CHARACTER SET utf8mb4`; recreate |
| Rollback fails on MySQL but works on Postgres | FK auto-dropped index; use `Schema::hasIndex()` guard (already applied in 2026_04_28_171727 migration) |

---

**This document is committed to the feature branch alongside the foundation verification artifact. It becomes part of the permanent platform infrastructure record.**
