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
