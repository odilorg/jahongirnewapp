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
