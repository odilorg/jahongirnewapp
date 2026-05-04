# Mixed-currency payment SOP

**Audience:** super_admin / admin / manager (operators with cashier-only role do NOT use this — they continue with the bot for same-currency).

**Use when:** a guest pays one booking with two settlement instruments in DIFFERENT currencies (e.g. UZS card + USD cash, EUR cash + UZS card).

If both instruments are the same currency → cashier uses the bot's `[➗ Разбить (наличные + карта)]` option, not this SOP.

---

## Step-by-step

### 1. Confirm the situation
- Booking has ONE total in one currency on Beds24 (e.g. 1,115,000 UZS).
- Guest is paying in TWO different currencies.
- An open shift exists for the cashier handling the till.

### 2. Open the admin tool
1. Login at `https://jahongir-app.uz/admin`
2. Navigate: Cash Operations → Cash Transactions
3. Click **💱 Mixed-currency journal** (top-right header button)

### 3. Fill the form

| Field | What to enter |
|---|---|
| **Open shift** | The shift currently open at the till (auto-list of open shifts). |
| **Beds24 booking ID** | The numeric Beds24 ID. The form validates the booking exists in `beds24_bookings`. |
| **Base currency** | The currency the booking is presented at on Beds24 (commercial truth). Sum-lock will reconcile to this. |
| **Leg 1 currency / amount / method** | First settlement instrument (e.g. UZS, 500000, card). |
| **Leg 2 currency / amount / method** | Second settlement instrument (e.g. USD, 50, cash). |

### 4. Submit
- ✅ **Sum-lock passes** → success notification with journal UUID + both transaction IDs. Done.
- ⚠ **Sum-lock fails** → red notification "Mixed-currency sum-lock failed in {base}: legs total X, booking expects Y (tolerance ±Z)". Adjust amounts and resubmit.

### 5. Verify
- Filter the cash transactions list by the journal UUID (or look at the most recent two rows).
- Both rows show the `💱` badge and the journal UUID column.

---

## Rules

| Rule | Enforced where |
|---|---|
| Same-currency split is NOT done here — bot handles it | Service rejects same-currency call |
| Maximum 2 legs per journal | Form has exactly 2 leg sections |
| Base currency must be UZS / USD / EUR | Form picker; service validates |
| Both legs must reference the SAME booking | Action constructs both DTOs from one booking ID |
| FX rates are FROZEN at submit (from `preparePayment`) — they cannot drift mid-session | Service implementation |
| Audit trail visible in admin table — `journal_entry_id` column groups the two legs | Resource table column |

---

## Tolerances (sum-lock)

The system absorbs small rounding noise:

| Base currency | Tolerance |
|---|---|
| UZS | ±100 |
| USD | ±0.50 |
| EUR | ±0.50 |

Anything beyond → form rejects. Operator either adjusts the amounts or escalates to a manager.

---

## When NOT to use this tool

- ❌ Same-currency split (cash + card both in UZS) — use the bot's split option
- ❌ Three or more instruments — manual workaround required (record two as a journal, the third manually with manager note); flag for ops as a Phase 2 trigger
- ❌ A single payment that the guest just hasn't completed yet (deposit + balance) — will be a Phase 2 workflow; for now record only what's actually paid

---

## Tracking

Each journal logs:
- `journal_entry_id` (UUID, shared by both legs)
- `payment_group_type='split'`
- `base_currency_for_split` = the operator's chosen base
- `journal_status='complete'` on success
- `bot_session_id='admin:<userId>:<shiftId>:<timestamp>'` so logs distinguish admin-originated entries

The daily Telegram report's `by_source` block treats both legs as `cashier_bot` source. The per-leg `payment_method` shows correctly in the `by_method` breakdown.

---

## Demand-trigger thresholds (from PHASE_1_5_PLAN.md)

If mixed-currency events hit **≥3 in any 2-week window**, request Phase 1.5.2 (bot UX) build so cashiers don't need admin involvement. Track via the daily audit count.
