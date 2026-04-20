# Lead CRM — Architectural Principles

Short durable reference for anyone (human or Claude) touching the Lead
CRM module. Read this before adding features. Violations of these rules
during review block the PR.

## Module boundary

Lead CRM lives in these namespaces:

```
app/Actions/Leads/*
app/Enums/Lead*
app/Exceptions/Leads/*
app/Filament/Pages/FollowUpQueuePage.php
app/Livewire/FollowUpQueue/*
app/Models/Lead.php, LeadInterest, LeadInteraction, LeadFollowUp, LeadEmailIngestion
app/Observers/LeadInteractionObserver.php, LeadFollowUpObserver.php
app/Services/Zoho/*                     (inbound mail ingestion client + DTO)
config/leads.php, config/zoho.php
database/migrations/…create_lead*, …lead_email_ingestions…
resources/views/filament/pages/follow-up-queue/*
tests/Feature/Leads/*
```

Outside code may reference these **only** via the Actions listed below.
Lead CRM code may import only:

- its own namespace
- `App\Models\User` (nullable FK for `assigned_to`, `user_id`)
- `App\Models\TourProduct` (nullable FK on `lead_interests.tour_product_id`)

Lead CRM must **not** import `BookingInquiry`, `Beds24*`, `Cashier*`,
`InquiryStay`, or any other domain module. When Lead → Inquiry
conversion lands (Phase 4) it will live in a dedicated action that
owns the cross-module boundary.

---

## 1 · Actions are the sole mutation surface

Every write to a Lead CRM table must go through a class under
`App\Actions\Leads\*`. Filament resources, Livewire components,
observers, seeders, console commands, queued jobs, future webhook
controllers — all of them call actions.

**Prohibited** outside of factories / seeders / the actions themselves:

- `Lead::create(...)`, `$lead->update(...)`, `$lead->delete(...)`
- `LeadFollowUp::create(...)` / updates / deletes
- `LeadInteraction::create(...)` / updates (edits are forbidden anyway —
  `LeadInteraction` is append-only, `const UPDATED_AT = null`)
- `LeadInterest::create(...)` / updates / deletes
- `LeadEmailIngestion::create(...)` (owned by `IngestEmailAsLead`)

Every action must be **idempotent**. Re-running the same action with
the same inputs produces the same result and makes zero extra writes.

### Current action set

| Action | Purpose |
|---|---|
| `FindOrCreateLeadByContact` | Resolve a contact (priority: tg > wa > phone > email) to exactly one lead; throw `AmbiguousLeadMatchException` on conflict |
| `LogInteraction` | Append a `LeadInteraction` row |
| `CreateFollowUp` | Open a follow-up on a lead |
| `CompleteFollowUp` | Mark a follow-up done; idempotent on already-done |
| `SnoozeFollowUp` | Push `snoozed_until` forward; rejects past datetimes and non-open rows |
| `TransitionLeadStatus` | State-machine-gated status change; writes an internal-note interaction |
| `SetLeadPriority` | No-op when unchanged |
| `UpdateLeadContact` | Whitelisted to name/phone/email/whatsapp_number only |
| `IngestEmailAsLead` | Inbound email → lead; dedupe by `remote_message_id` |

---

## 2 · Models carry data, not behavior

Models contain:

- `$fillable`
- `$casts` (enums go here)
- relations (`hasMany`, `belongsTo`, `hasOne` including `latestOfMany`)
- query scopes (pure, read-only; `scopeOverdue`, `scopeDueToday`, etc.)
- `$table` when non-default

Models do **not** contain:

- business rules
- cross-table writes
- transitions or policies

One historical exception: `Lead::refreshNextFollowupAt()` performs a
cross-table read and save. This is accepted as a denormalization
helper invoked by `LeadFollowUpObserver`. If a second denormalized
field is ever added, extract both into a `RefreshLeadDenormals`
service and delete the model method.

---

## 3 · Observers enforce invariants — never policies

Observers keep denormalized fields in sync with source rows. Nothing
more.

Allowed:

- `last_interaction_at` ← latest `occurred_at`
- `next_followup_at` ← earliest effective-due of open follow-ups

Not allowed in observers:

- "if new lead, schedule initial follow-up" (that's a policy →
  `IngestEmailAsLead`, the "New lead" button, etc.)
- "if status turns converted, mark all follow-ups done" (policy →
  dedicated action when needed)
- any external side effect (Telegram, email, Zoho CRM push)

Litmus test: **would disabling the observer only cause denormalized
fields to drift, or would it change what happens to the business?**
If the business changes, move it to an action.

---

## 4 · Services wrap external systems only

External systems get a service under `app/Services/<Vendor>/`.

- `App\Services\Zoho\ZohoMailInboundClient` wraps `webklex/php-imap`
- `App\Services\Zoho\InboundEmail` is the DTO handed to domain code

Rules:

- Services return **DTOs or primitives** — never raw library objects.
  If `Webklex\PHPIMAP\Message` leaks outside `ZohoMailInboundClient`,
  that's a bug.
- Services hold no business logic. They speak the external protocol
  and hand normalized data to actions.
- One vendor = one service (or one directory). Future additions:
  `App\Services\Zoho\ZohoCrmClient` (outbound sync), `App\Services\Telegram\*`
  (inbound bot messages).

Test strategy: bind a fake implementation of the service in the
container; tests exercise the action + DTO path without live IMAP /
HTTP.

---

## 5 · Presentation asks the domain layer what is allowed

Filament pages, Livewire components, and Blade views must not hardcode
domain decisions.

Wrong:
```php
Tables\Columns\SelectColumn::make('status')
    ->options(['new', 'contacted', 'qualified', 'quoted', ...])
```

Right:
```php
$options = TransitionLeadStatus::allowedTransitionsFrom($lead->status);
```

The trait `HasLeadRowActions` is presentation glue; the domain
questions it asks (`allowedTransitionsFrom`, enum `cases()`, config
`directions`) are the domain layer answering.

Controllers, when they appear (webhook endpoints, public API, Claude
tool server), stay thin: validate input → call action → return
response.

---

## 6 · Enums for every state or type column

No magic strings in tables. If a column holds one of a fixed set of
values, it gets an enum in `App\Enums\Lead*`. Actions accept the
enum, not the string. The enum's backing value is stored in the DB.

Current enums (10):

- `LeadStatus`, `LeadSource`, `LeadPriority`, `LeadContactChannel`
- `LeadInteractionChannel`, `LeadInteractionDirection`
- `LeadInterestFormat`, `LeadInterestStatus`
- `LeadFollowUpType`, `LeadFollowUpStatus`

Enum changes (add case, remove case) require a schema review — enums
are storage-coupled.

---

## 7 · Every integration boundary uses a DTO

- Incoming: external systems → DTO → action. `InboundEmail` is the
  canonical example.
- Outgoing: action → DTO → external system. When Zoho CRM outbound
  sync lands, it'll map `Lead` → `ZohoLeadRecord` DTO → `ZohoCrmClient`.

DTOs live next to their service. DTOs are readonly PHP 8.2+ classes.
Never pass raw Eloquent models into a service method — pass only
the fields it actually needs, or a purpose-built DTO.

---

## Testing contract

Every action has a feature test. The test runs against the real MySQL
test DB (`jahongirnewapp_test`), uses `RefreshDatabase`, and asserts
behaviour — not implementation.

Feature tests for Filament pages use Livewire's test helpers
(`Livewire::test(...)->callAction(...)`). They verify that the UI
wires through to the correct action, not that the action works
correctly (the action's own test covers that).

Phase 1 → 3a coverage: **41 tests, 143 assertions, all green on
MySQL 8.0.** Target: any new action adds ≥2 tests (happy path +
most-likely failure).

---

## Allowed future additions

- More actions under `App\Actions\Leads\*`
- More services under `App\Services\*` (one directory per external
  vendor)
- Webhook controllers (thin; under `App\Http\Controllers\Webhooks\*`)
- A Claude MCP tool server exposing the actions verbatim — this is
  why the actions are kept small and single-purpose

## Forbidden additions without an ADR

- A repository layer over Eloquent
- A mediator / command bus over actions
- A "LeadService" god-object
- Cross-domain coupling (Lead → BookingInquiry direct import, etc.)
- Business logic in migrations

If one of these feels necessary, write a one-page ADR in
`docs/adr/` explaining the problem, the two options considered,
and why the rule needs to bend. Then discuss before merging.

---

_Last updated alongside Phase 3a (Zoho Mail inbound ingestion)._
