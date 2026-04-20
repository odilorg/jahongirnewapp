# Refactor plan — extract `ProcessBookingMessage`

**Status:** APPROVED 2026-04-20 with 4 adjustments (see §0 below). Execution started with §4.1.
**Scope gate:** hotel-only runtime. `ProcessBookingMessage` is dispatched **only** by `TelegramWebhookController` and `BookingWebhookController` — both the `@j_booking_hotel_bot` path. Zero tour callers. Zero shared-runtime change. Safe under the scope gate.
**Architectural authority:** `docs/architecture/PRINCIPLES.md` + `docs/architecture/LAYER_CHEAT_SHEET.md`.

---

## 0. Approved adjustments (2026-04-20)

1. **Namespace** — `app/Actions/BookingBot/Handlers/` (not `/BookingBot/`). Reserves the flat `/BookingBot/` space for future non-handler concerns (DTOs, formatters, routers).
2. **ModifyBooking split** — confirmed: parent orchestrator + 3 step-actions (dates, guest, room). See §4.7.
3. **Golden-master fixtures** — use BOTH synthetic (code-derived) AND real anonymised Telegram updates. Synthetic guarantees intent coverage; real catches shape drift we wouldn't invent.
4. **Pre-extraction Context DTO + logging parity** — add Step 0.5 before 4.1: introduce `BookingBotContext` (staff, chatId, messageId, rawUpdate) so extracted Actions don't grow long parameter lists; add an explicit log-output parity check to the golden master (not just reply text — log structure + keys too).

---

## 1. Why this

`ProcessBookingMessage` is **1,025 LOC in a single Job class** that violates 4 of the 11 principles at once:

| Principle | Violation |
|---|---|
| **P3** Business logic in Actions/Services | Seven workflow handlers live inside the Job (availability check, create/cancel/modify/view bookings, callback query, phone contact) |
| **P4** Controllers/Jobs are thin | 1,025 LOC is not thin — it is the largest Job in the codebase |
| **P5** Single source of truth for rules | Room-availability logic, booking-intent validation, and Beds24 mapping rules are all embedded in Job methods — duplicatable, hard to reuse |
| **P6/P7** External systems in adapters | Mostly OK (services are already injected), but some direct `RoomUnitMapping::all()` queries leak domain-data concerns into the Job |

It is the **largest single violation** currently in the codebase. Extracting it is pure hotel-only work — no tour runtime is touched.

---

## 2. Current structure (mapped from the source)

```
ProcessBookingMessage (1,025 LOC)
├── handle($auth, $parser, $beds24, $telegram, $formatter, $keyboard)   90 LOC
│   (entry point; dispatches to handlers below based on parsed intent)
│
├── handlePhoneContact         20 LOC   (non-command phone-only messages)
├── handleCommand              29 LOC   (intent router — switch by intent type)
├── handleCheckAvailability   125 LOC   (parse dates → RoomUnitMapping::all() → Beds24::checkAvailability → format)
├── handleCreateBooking       108 LOC   (parse guest+dates → Beds24::createBooking → confirm)
├── handleViewBookings        177 LOC   (query Beds24 → filter → format)
├── handleCancelBooking       104 LOC   (confirm cancel → Beds24::cancel → notify)
├── handleModifyBooking       252 LOC   ← biggest; likely needs further split
└── handleCallbackQuery        91 LOC   (inline-button callbacks; editing messages)
```

Already-injected services (reuse as-is, no new adapters needed):

- `StaffAuthorizationService` — staff staff-auth lookups
- `BookingIntentParser` — NLP intent extraction
- `Beds24BookingService` — all Beds24 API calls
- `TelegramBotService` — message send + edit
- `StaffResponseFormatter` — format reply strings
- `TelegramKeyboardService` — build inline keyboards

Direct DB use: **4 occurrences** (mostly `RoomUnitMapping::all()` inside `handleCheckAvailability` and `handleCreateBooking`).

---

## 3. Target structure

Namespace: `app/Actions/BookingBot/Handlers/` (CLAUDE.md convention: `app/Actions/<Feature>/`; `/Handlers/` subfolder reserves room for non-handler siblings).

```
app/Jobs/ProcessBookingMessage.php        ~100 LOC
│   Pure router: parse intent → dispatch to Action → send response
│
app/Actions/BookingBot/
├── BookingBotContext.php                  ~30 LOC   (DTO — staff, chatId, msgId, rawUpdate)
└── Handlers/
    ├── HandlePhoneContactAction.php       ~30 LOC
    ├── HandleCallbackQueryAction.php     ~100 LOC
    ├── CheckAvailabilityAction.php       ~140 LOC   ★ most-exercised path
    ├── CreateBookingFromMessageAction.php ~120 LOC
    ├── ViewBookingsFromMessageAction.php  ~180 LOC
    ├── CancelBookingFromMessageAction.php ~110 LOC
    └── ModifyBooking/                     split — see §4.7
        ├── ModifyBookingFromMessageAction.php  (orchestrator, ~80 LOC)
        ├── ModifyBookingDatesStep.php          (~70 LOC)
        ├── ModifyBookingGuestStep.php          (~70 LOC)
        └── ModifyBookingRoomStep.php           (~70 LOC)
```

Each Action:
- Single public `execute(...)` method
- Constructor-injected dependencies
- Returns a plain string (reply text) OR a structured DTO — match existing Job method signatures
- No Telegram send (Job owns that)
- No static state, no closures with logic

---

## 4. Extraction sequence (safe, atomic, one commit per step)

Each step: **pure extraction — zero behaviour change**. Each step is independently green on CI. Each step is independently revertable.

### 4.0 · Prep — golden-master tests

Before extracting anything, pin current behaviour.

- Branch: `refactor/extract-process-booking-message`
- New test file `tests/Feature/BookingBot/GoldenMasterTest.php`
- Feed canned Telegram update payloads (one per intent type: availability, create, view, cancel, modify, callback, phone-only, unknown)
- Assert the current reply string matches a fixture file committed alongside the test

Fixtures are **both**:
- **Synthetic** — handcrafted one-per-intent payloads derived from code paths; guarantees every intent branch is exercised.
- **Real anonymised** — captured from historical Telegram updates (guest names + phone suffixes scrubbed); catches update-shape fields we'd never invent (`edit_date`, `forward_from`, `via_bot`, etc.).

The golden master asserts two parity axes: **(a) reply text** matches fixture, **(b) Log structure** — the set of log channels + context keys produced during the handler run match the baseline (catches "we moved logic but dropped a log line" regressions).

### 4.0.5 · Introduce `BookingBotContext` DTO (pre-extraction)

Extract a tiny value object **before** the first handler comes out — otherwise each Action grows a long positional parameter list that is painful to unwind later.

```
app/Actions/BookingBot/BookingBotContext.php

final readonly class BookingBotContext
{
    public function __construct(
        public ?Staff $staff,          // null before phone-link
        public int $chatId,
        public int $messageId,
        public array $rawUpdate,       // original Telegram payload
    ) {}
}
```

- Built once at the top of `ProcessBookingMessage::handle()`.
- Passed to every handler Action as the first argument.
- `phone_contact` and callback-query paths build a pre-auth Context (staff=null); authorised paths build a full Context.

Commit this step separately (tiny diff, zero behaviour change, buys us clean signatures).

### 4.1 · Extract `HandlePhoneContactAction` (smallest, proves pattern)

- Create `app/Actions/BookingBot/HandlePhoneContactAction.php`
- Move body verbatim
- In Job: `app(HandlePhoneContactAction::class)->execute(...)`
- Run golden master → green
- Commit

### 4.2 · Extract `HandleCallbackQueryAction`

Same pattern. Callback-query handler has slightly different dependencies (`$authService`, `$telegram`, `$beds24`, `$keyboard`) — pass them in via constructor injection.

### 4.3 · Extract `CheckAvailabilityAction` ★ highest visibility

This is **the path a user hits when they write "check avail jan 2-3"** — the path we just debugged with the `room_unit_mappings` incident.

- Action accepts `array $parsed` + `Beds24BookingService $beds24`
- Inside the action, replace `RoomUnitMapping::all()` with a **scope on the model**: `RoomUnitMapping::query()->active()->get()` (adds one scope method to the model — tiny, principle P2-compliant)
- Everything else moves verbatim

### 4.4 · Extract `CreateBookingFromMessageAction`

### 4.5 · Extract `CancelBookingFromMessageAction`

### 4.6 · Extract `ViewBookingsFromMessageAction`

(Three straightforward extractions — same pattern as 4.3. Each one commit.)

### 4.7 · Extract + split `ModifyBookingFromMessageAction`

252 LOC is too big for one Action — that's re-creating the god-class problem in miniature. Split by sub-intent:

- Parent Action `ModifyBookingFromMessageAction` — orchestrates, picks the right step
- Steps per modifiable field:
  - `ModifyBookingDatesStep`
  - `ModifyBookingGuestStep`
  - `ModifyBookingRoomStep`

Each Step: ≤ 100 LOC.

If the internal sub-intents are discoverable from the parsed structure, the parent is a 5-line dispatch. If not, a match expression on the modify-type field does the routing.

### 4.8 · Collapse the Job

With all handlers extracted, `ProcessBookingMessage::handle()` becomes:

```php
public function handle(
    StaffAuthorizationService $authService,
    BookingIntentParser $parser,
    Beds24BookingService $beds24,
    TelegramBotService $telegram,
    StaffResponseFormatter $formatter,
    TelegramKeyboardService $keyboard,
): void {
    $update = $this->update;

    // (existing bootstrap + staff-auth check — keep)

    $parsed = $parser->parse(...);

    $reply = match ($parsed['intent'] ?? 'unknown') {
        'check_availability' => app(CheckAvailabilityAction::class)->execute($parsed, $beds24),
        'create_booking'     => app(CreateBookingFromMessageAction::class)->execute($parsed, $staff, $beds24),
        'view_bookings'      => app(ViewBookingsFromMessageAction::class)->execute($parsed, $beds24),
        'cancel_booking'     => app(CancelBookingFromMessageAction::class)->execute($parsed, $staff, $beds24),
        'modify_booking'     => app(ModifyBookingFromMessageAction::class)->execute($parsed, $staff, $beds24),
        default              => $this->unknownIntentHelp(),
    };

    $telegram->sendMessage($chatId, $reply);
}
```

Target size: **≤ 100 LOC total**. The Job's only responsibility becomes "receive update → route intent → send reply."

### 4.9 · Arch-lint baseline refresh + final commit

- Run `scripts/arch-lint.sh`
- Confirm the old `ProcessBookingMessage` violation count drops
- Regen baseline if needed: `scripts/arch-lint.sh --regen-baseline > scripts/arch-lint-baseline.txt`
- Commit

---

## 5. Test plan per step

Each commit must pass:

1. **Golden-master test** (from 4.0) — all captured intents still produce the same reply string
2. **New per-Action unit test** — at least one happy path + one error path
3. **Full bot test suite** (if any exists pre-refactor; otherwise step 4.0's golden master IS the suite)
4. **`scripts/arch-lint.sh --staged`** — zero new violations from the commit
5. **`php -l`** on every changed file
6. **Manual smoke on staging** — once per batch (say, after 4.3, 4.6, 4.8) message the bot and verify reply

If any step breaks the golden master, **revert that commit, not just fix-forward**. Pure-extraction commits should be byte-for-byte identical in reply output.

---

## 6. Risk + rollback

| Risk | Mitigation |
|---|---|
| Dependency injection ordering changes behaviour | Each extraction preserves the original constructor arg order verbatim |
| Lost edge case in 252-LOC `handleModifyBooking` | Split into 3+1 instead of one megafile; each sub-step has its own golden-master case |
| `RoomUnitMapping::all()` → scope change behaves differently | Introduce scope as an aliasing method returning `self::all()` first; refactor the scope contents in a separate later PR |
| Telegram-side change in test fixtures | Fixtures are captured once from real data; if Telegram update shape changes, that's a separate issue, not caused by this refactor |
| Half-migrated state lands in prod (some handlers extracted, others not) | That IS the expected state between commits — each commit is fully deployable. No half-migrations by design. |

Rollback strategy: any commit is a `git revert` away. The extracted Action files are additive; reverting removes them. The Job's call sites revert back to the inlined method bodies.

---

## 7. Non-goals (out of scope for this ticket)

Deliberately NOT doing these — they're either out-of-scope or follow-up tickets:

- ❌ Rewriting `BookingIntentParser` — it's already a separate Service, fine as-is
- ❌ Adding new bot features
- ❌ Migrating Beds24 calls into a new adapter style (already `Beds24BookingService`)
- ❌ Writing Form Requests for the Job (jobs don't take HTTP requests)
- ❌ Touching `TourCalendar`, `BookingInquiry`, or any shared booking aggregate
- ❌ Ledger integration (that's Phase C — L-007 onward)

---

## 8. Estimate

- 4.0 golden-master tests: **~1 day**
- 4.1–4.6 simple extractions (6 actions): **~1.5 days total** (small, repetitive, low-risk)
- 4.7 Modify split: **~1 day** (biggest extraction, warrants care)
- 4.8 Job collapse: **~0.5 day**
- 4.9 lint baseline + final: **~0.5 day**

**Total: ~4.5 days focused work.** Can be done in parallel to other work since each commit is a self-contained unit.

---

## 9. Success criteria

- `wc -l app/Jobs/ProcessBookingMessage.php` → **≤ 100 LOC** (from 1,025)
- 6+ new files under `app/Actions/BookingBot/`, each **≤ 250 LOC**
- Golden-master test suite in `tests/Feature/BookingBot/`, all green
- `scripts/arch-lint.sh` reports fewer violations than before (one god-job gone)
- `@j_booking_hotel_bot` behaviour unchanged — same replies for same inputs
- Zero production incident during or after the rollout

---

## 10. What I need from you before starting

1. **Approval of this plan.**
2. **Confirmation of Action namespace** — `app/Actions/BookingBot/` or a different name (e.g., `app/Actions/HotelBooking/`, `app/Actions/Hotel/Booking/`).
3. **Scope confirmation for the ModifyBooking split (§4.7)** — I'm proposing 3 step-sub-actions. If you prefer a different split or "leave it as one action for now", say so.
4. **Golden master fixtures** — I can capture synthetic ones from the code paths alone, OR you can send a few anonymised real Telegram updates (richer coverage). Your choice.

Once approved, step 4.0 (tests) lands first. Then I pause and show you the first extracted Action before continuing — so you see the pattern on a small diff before we touch the bigger handlers.
