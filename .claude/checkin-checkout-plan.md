# Cashier Bot Check-in / Check-out Feature Plan

## Product shape
- `🏨 Arrivals Today` + `🚪 Departures Today` buttons in cashier bot
- Pick booking → summary → confirm → backend transition

## Microphases
- **P1** — CheckInService + CheckOutService + tests (no Telegram, no Beds24)
- **P2** — Backend queries: arrivals today, departures today, compact summaries
- **P3** — Telegram menu buttons + arrivals listing
- **P4** — Telegram check-in confirmation flow
- **P5** — Telegram departures listing
- **P6** — Telegram check-out confirmation flow
- **P7** — Beds24 async sync jobs
- **P8** — Warnings/blocks: unpaid balance, invalid state

## Architecture
- `app/Services/Stay/CheckInService.php`
- `app/Services/Stay/CheckOutService.php`
- Telegram controller only: list → pick → call service → respond
- No business rules in controller

## Transition rules

### Check-in (allow if):
- exists, not cancelled, not no_show, not checked_in, not checked_out
- is in check-in-eligible state (e.g. `confirmed`)

### Check-out (allow if):
- exists, currently `checked_in`, not checked_out, not cancelled, not no_show

## Audit log (both actions):
- booking_id, actor user_id, action (check_in/check_out)
- old_status, new_status, source = `telegram_cashier_bot`, timestamp
- Use existing booking activity model if present

## Tests (CheckInService)
- confirmed → can check in
- cancelled → blocked
- no_show → blocked
- already checked_in → blocked
- already checked_out → blocked

## Tests (CheckOutService)
- checked_in → can check out
- confirmed (not checked_in) → blocked
- cancelled → blocked
- no_show → blocked
- already checked_out → blocked

## Tests (audit)
- successful check-in writes activity
- successful check-out writes activity
- blocked transition writes no status change

## Branch: `feat/cashier-bot-checkin-checkout-p1`
## Design constraints: No Telegram UI, no Beds24, no unrelated refactors
