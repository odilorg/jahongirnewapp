# INVENTORY — Jahongir Hotel Operations System

**Phase:** 1 · **Generated:** 2026-04-18 · **Commit:** `09ade0f` on `main`
**Method:** automated scan of `~/projects/jahongirnewapp` — verified against VPS HEAD

---

## 1. Repo baseline

| Item | Value |
|---|---|
| Framework | **Laravel 10** (`^10.10`) ⚠️ *PROJECT_CONTEXT.md says Laravel 11 — outdated* |
| PHP | `^8.1` |
| Admin panel | Filament **3** (`^3.2`) |
| Frontend reactivity | Livewire 3 (`^3.5`) — **1 component total**, effectively unused |
| DB | MySQL · **138 tables** from **266 migrations** |
| Queues | Laravel Queues (Redis via `predis/predis ^3.4`) |
| Total `app/` size | **4.7 MB** · **71,010 LOC** PHP across 843 files |
| Tests | **79 test files** (coverage depth unmeasured — Phase 3.5 task) |
| Git activity | Active — 20 commits in April 2026 alone, phases 17–23 |

---

## 2. LOC & file count per layer

| Layer | Files | LOC | Avg LOC/file | Assessment |
|---|---:|---:|---:|---|
| **Controllers** | 19 | **10,040** | 528 | 🔴 fat — should be ≤100 |
| **Services** | 81 | **18,619** | 230 | 🟡 acceptable avg, but has god-services |
| **Actions** | **4** | 662 | 166 | 🔴 **severe under-adoption** — should be dozens |
| **Models** | 105 | 7,198 | 68 | ✅ avg OK — few fat outliers |
| **Jobs** | 11 | 2,071 | 188 | 🟡 one god-job (1025 LOC) skews avg |
| **Observers** | 6 | 549 | 92 | ✅ reasonable |
| **Policies** | 32 | 3,448 | 108 | ✅ reasonable |
| **Requests** | **8** | 348 | 44 | 🔴 **severe under-adoption** — validation likely in controllers |
| **Middleware** | 16 | 616 | 39 | ✅ |
| **Filament** | 239 | **17,951** | 75 | 🟡 very large admin surface |
| **Livewire** | 1 | 25 | 25 | — unused |
| **Console** | 43 | 6,639 | 154 | 🟡 commands carry business logic (phase 1 finding) |
| **Mail** | 3 | 154 | 51 | — |
| **Enums** | 17 | 691 | 41 | ✅ |
| **DTO** | 3 | 242 | 81 | ⚠️ **duplicate dir** (see §4) |
| **DTOs** | 8 | 368 | 46 | ⚠️ **duplicate dir** (see §4) |
| **Contracts** | 4 | 187 | 47 | ✅ |
| **Casts** | 1 | 29 | 29 | — |
| **Exceptions** | 19 | 283 | 15 | ✅ |
| **Providers** | 7 | 342 | 49 | ✅ |
| **Support** | 3 | 407 | 136 | ✅ |
| **TOTAL app/** | **843** | **71,010** | — | |

**Structural red flags:**
- **Only 4 Actions** for 81 Services — action-per-use-case pattern barely adopted
- **Only 8 Form Requests** for 19 controllers — most validation is inline or absent
- **10,040 LOC across 19 controllers** — average 528 LOC, 7 over 1,000 LOC each

---

## 3. Top 20 largest files (LOC leaderboard)

| # | LOC | File | Layer | Concern |
|---:|---:|---|---|---|
| 1 | **2,107** | `app/Services/OperatorBookingFlow.php` | Services | 🔴 god-service |
| 2 | 1,960 | `app/Filament/Resources/BookingInquiryResource.php` | Filament | 🔴 god-resource |
| 3 | **1,848** | `app/Http/Controllers/HousekeepingBotController.php` | Controllers | 🔴 god-controller |
| 4 | **1,819** | `app/Http/Controllers/CashierBotController.php` | Controllers | 🔴🔴 god-controller **in money domain** |
| 5 | 1,492 | `app/Http/Controllers/TelegramPosController.php` | Controllers | 🔴🔴 god-controller **in money domain (POS)** |
| 6 | 1,189 | `app/Http/Controllers/Beds24WebhookController.php` | Controllers | 🔴 god-controller |
| 7 | 1,061 | `app/Http/Controllers/TelegramDriverGuideSignUpController.php` | Controllers | 🔴 god-controller |
| 8 | **1,025** | `app/Jobs/ProcessBookingMessage.php` | Jobs | 🔴 god-job |
| 9 | 1,017 | `app/Services/Beds24BookingService.php` | Services | 🟡 large |
| 10 | 793 | `app/Services/TelegramReportFormatter.php` | Services | 🟡 |
| 11 | 738 | `app/Services/DriverDispatchNotifier.php` | Services | 🟡 |
| 12 | 727 | `app/Http/Controllers/KitchenBotController.php` | Controllers | 🔴 god-controller |
| 13 | 725 | `app/Filament/Pages/TourCalendar.php` | Filament | 🟡 large page |
| 14 | 717 | `app/Console/Commands/ImportToursFromStatic.php` | Console | 🟡 |
| 15 | 680 | `app/Filament/Pages/Reports.php` | Filament | 🟡 reporting page, logic-heavy |
| 16 | 666 | `app/Services/OwnerAlertService.php` | Services | 🟡 |
| 17 | 592 | `app/Http/Controllers/TelegramController.php` | Controllers | 🔴 god-controller |
| 18 | 554 | `app/Services/TelegramReportService.php` | Services | 🟡 |
| 19 | 551 | `app/Console/Commands/TourSendReminders.php` | Console | 🟡 |
| 20 | 506 | `app/Services/AdvancedReportService.php` | Services | 🟡 |

**Combined top-5: 9,226 LOC = 13% of entire app/** concentrated in 5 files.

---

## 4. Duplicate structures

### 4.1 Duplicate DTO directory

| `app/DTO/` (3 files) | `app/DTOs/` (8 files) |
|---|---|
| `GroupAmountResolution.php` | `ResolvedTelegramBot.php` |
| `PaymentPresentation.php` | `TelegramApiResult.php` |
| `RecordPaymentData.php` | `Fx/*` (6 files) |

**Action required (Phase 5):** consolidate under `app/DTOs/`, add sub-namespaces (`DTOs/Payment/`, `DTOs/Telegram/`).

### 4.2 Duplicate service classes (same basename, two locations)

4 services exist in **both** `app/Services/` **and** `app/Services/Fx/`:

| Basename | `app/Services/` | `app/Services/Fx/` |
|---|---|---|
| `BotPaymentService.php` | ✅ | ✅ |
| `FxManagerApprovalService.php` | ✅ | ✅ |
| `FxSyncService.php` | ✅ | ✅ |
| `OverridePolicyEvaluator.php` | ✅ | ✅ |

**This is ambiguous and dangerous.** Two classes with the same name in different namespaces; callers may wire up the wrong one. Requires deep Phase 3 audit to determine which is live and which is dead.

### 4.3 Sibling-named services with unclear separation

| Pair | Likely concern |
|---|---|
| `TelegramKeyboardBuilder.php` + `TelegramKeyboardService.php` | overlap in responsibility |
| `TelegramBotService.php` + `TelegramBookingService.php` + `TelegramPosService.php` | bot-per-service pattern, not a shared kernel |
| `ReconciliationService.php` + `Fx/WebhookReconciliationService.php` | two reconciliation layers — ambiguous ownership |

---

## 5. Suspicious / backup files

### 5.1 `.backup` files still in `app/`

```
app/Http/Controllers/VoiceAgentController.php.backup
app/Models/User.php.backup
app/Providers/Filament/AdminPanelProvider.php.backup
app/Services/TelegramKeyboardBuilder.php.backup-20251018-235037
```

### 5.2 Other locations

```
public/enhanced-voice-test.html.bak
```

### 5.3 Archived but still in repo

`app/Filament/_archived/` — **40 files**, 5 legacy Resources (Booking, Chat, Guest, ScheduledMessage, TerminalCheck, Tour, TourExpense, Zayavka) with pages/relation managers. Moved out of active Filament tree but not deleted; still counted in Filament LOC.

**Recommendation (Phase 5, P2):** delete backups + `_archived/` — git history preserves them.

---

## 6. Debug leftovers

Scanned `app/` for: `dd(`, `dump(`, `ray(`, `var_dump(`, `print_r(`, `die(`, `exit(`.

✅ **Zero occurrences across all 843 PHP files.** Good discipline.

---

## 7. Application-layer HTTP leaks

Rule: services/actions/jobs must not import `Request` or call `request()`.

**Violations (2):**
- `app/Services/Telegram/BotAuditLogger.php` — reads `request()` directly
- `app/Services/FxManagerApprovalService.php` — takes `Request $request` as dep (note: also in duplicate list §4.2)

Narrow, fixable. Formal entries in Phase 3 `VIOLATIONS.md`.

---

## 8. Fat-model leaderboard (LOC > 150)

| LOC | Model | Methods / Relations | Non-relation methods | Concern |
|---:|---|---|---:|---|
| **408** | `CashierShift.php` | — | — | 🔴 **biggest model, money domain** |
| 363 | `BookingInquiry.php` | 18 / 13 | 5 | 🟡 |
| 345 | `Booking.php` | 11 / 6 | 5 | 🟡 |
| **259** | `CashTransaction.php` | 21 / 6 | **15** | 🔴 **heavy business logic in money model** |
| 198 | `User.php` | — | — | 🟡 (has `.backup` twin) |
| 181 | `Beds24Booking.php` | 15 / 3 | **12** | 🔴 fat |
| 176 | `TelegramBot.php` | — | — | 🟡 |
| 171 | `TourProduct.php` | — | — | 🟡 |
| 169 | `CashDrawer.php` | — | — | 🟡 money domain |
| 168 | `RoomPriority.php` | — | — | 🟡 |
| 130 | `TelegramBotSecret.php` | — | — | ✅ borderline |
| 123 | `TelegramBotAccessLog.php` | — | — | ✅ |
| 123 | `Accommodation.php` | — | — | ✅ |
| 122 | `BookingFxSync.php` | — | — | ✅ |
| 117 | `TelegramServiceKey.php` | — | — | ✅ |

Models bypassing Eloquent with raw `DB::`: **1** (`app/Models/KitchenMealCount.php`) — to be checked in Phase 3.

---

## 9. Integration inventory

### 9.1 External services (confirmed from composer + env + code)

| Integration | Purpose | Entrypoints |
|---|---|---|
| **Beds24** | Hotel PMS sync (rooms, bookings, availability) | `Beds24WebhookController`, `Beds24BookingService`, 4 repair commands |
| **GetYourGuide (GYG)** | Channel manager — email-based ingestion | `GygController` + 5 GYG services + 5 `GygFetch*/Process*/Apply*` commands |
| **Octobank (Octo)** | Payment gateway | `OctoCallbackController`, `OctoPaymentService` |
| **Telegram Bot API** | 8 operational bots | 11 controllers / 11 services / 11 commands |
| **OpenAI** | Date extraction for booking parser | `OpenAIDateExtractorService` (via `openai-php/laravel ^0.11`) |
| **CBU / exchange rate APIs** | FX rates (5-layer fallback per PROJECT_CONTEXT) | `ExchangeRateService`, `OctoPaymentService` |
| **AWS S3** | Storage (AWS_* env vars present) | Laravel Filesystem |
| **SMTP / email** | Daily recaps, reminders, GYG notifications | `Messaging/EmailSender`, Mailables |
| **WhatsApp** | Guest messaging | `Messaging/WhatsAppSender` (check implementation) |
| **Dompdf** | PDF generation for contracts, tours | `barryvdh/laravel-dompdf ^3.0`, `Pdf/TourPdfExportService` |

### 9.2 No Telegram SDK package found

The app uses custom Guzzle-based Telegram integration through `Services/Telegram/TelegramTransport.php` — not the common `irazasyed/telegram-bot-sdk`. Homegrown transport. **Audit candidate** for Phase 3.

---

## 10. Validation of `.claude/PROJECT_CONTEXT.md`

`PROJECT_CONTEXT.md` (dated 2026-03-27) was treated as a source artifact and cross-checked against code.

### ✅ Accurate
- 16 feature blocks: confirmed — maps onto observed file structure
- Scheduled tasks table: matches `app/Console/Kernel.php` (with additions — see below)
- Telegram bot count (8): confirmed — 8 distinct webhook endpoints
- Models per domain: spot-checked, accurate

### ⚠️ Outdated / incomplete

| Claim | Actual |
|---|---|
| "Laravel 11" | **Laravel 10** (`^10.10` in composer.json) |
| Lists `TelegramBookingService` under Block 1 | Still exists, but `OperatorBookingFlow` (2107 LOC) is the real orchestrator and is not mentioned |
| Block 6 "CashierPaymentService" | **Actual file is `BotPaymentService` + duplicate in Fx/** — name in doc doesn't exist |
| Does not mention `Fx/` sub-namespace | 6 services live under `app/Services/Fx/` (FxSync, Settlement, Override, etc.) — entirely absent from doc |
| Does not mention `Stay/` sub-namespace | `CheckInService`, `CheckOutService`, `StayListService`, `BookingSummary` — not mentioned |
| Does not mention `Gyg/` sub-namespace | `GygInquiryWriter`, `GygTourProductMatcher` — not mentioned |
| Does not mention `app/Actions/` | Only 4 actions exist; doc is silent |
| Does not mention `Messaging/` sub-namespace | `EmailSender`, `WhatsAppSender`, `GuestContactSender`, `SendResult` — partially mentioned |

### ❌ Missing from doc entirely
- `SupplierPaymentResource` (Filament) — exists
- `GuestBalances`, `SupplierBalances` Filament pages — exist, money-critical
- `BookingInquiry` as the primary inquiry entity post-Phase 8 — dominant in recent commits
- `TourProduct`, `TourPriceTier`, `TourProductDirection` (tour catalog — phase 8 migration)
- `GygPipelineHealthWidget`, `DrawerBalanceWidget`
- `Fx/` pipeline: payment sync, approvals, settlement, reconciliation (recent, complex)
- `_archived/` Filament folder

**Recommendation:** Phase 2 `DOMAINS.md` becomes the new source of truth; `PROJECT_CONTEXT.md` updated afterward to reference it.

---

## 11. Money-flow spread (Phase 3.5 preview)

Grepping for money tokens (`amount|total|price|payment|cash|revenue|balance`) across `app/Services app/Actions app/Jobs`:

- **40 files** touch money concepts in application layer
- **8 controllers** touch money concepts directly (presentation-layer leak)
- Money lives in: `Booking`, `BookingInquiry`, `CashTransaction`, `CashierShift`, `CashDrawer`, `GuestPayment`, `SupplierPayment`, `AgentPayment`, `DriverPayment`, `CashExpense`, `BeginningSaldo`, `EndSaldo`, `BookingPaymentReconciliation`, `BookingFxSync`, `Beds24PaymentSync`, `Invoice`, `Expense`, `TourExpense`, `DailyExchangeRate`, `ExchangeRate` (~20 models)

**Verdict (preview):** money logic is **scattered** — no ledger core, no central event stream. This is the #1 strategic problem, confirming the ledger-core direction in `AUDIT_BRIEF.md`.

Formal deep-dive in `docs/architecture/MONEY_FLOW_DEEP_DIVE.md` (Phase 3.5).

---

## 12. Summary — top structural smells

1. 🔴 **7 god-classes** over 1,000 LOC (OperatorBookingFlow, BookingInquiryResource, and 5 controllers)
2. 🔴 **4 duplicate services** in `Services/` vs `Services/Fx/` — ambiguous wiring risk
3. 🔴 **Action pattern barely adopted** — 4 actions vs 81 services (ratio should be inverted for a system this size)
4. 🔴 **Form Requests under-used** — 8 Requests for 19 controllers; validation likely inline
5. 🔴 **Money logic scattered** across ~40 files, ~20 models — **no ledger core**
6. 🟡 **Duplicate DTO namespace** (`DTO/` + `DTOs/`)
7. 🟡 **Backup files in `app/`** (4) + archived Filament (40 files)
8. 🟡 **Stale doc** — `PROJECT_CONTEXT.md` says Laravel 11; reality is Laravel 10
9. 🟡 **2 HTTP leaks** in services (minor)

---

**Next phase:** `ROUTES_AND_ENTRYPOINTS.md` (Phase 1 part 2) → `DOMAINS.md` (Phase 2).
