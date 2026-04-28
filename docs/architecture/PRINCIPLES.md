# Architecture Principles

**Status:** MANDATORY. Every change touches these boundaries — understand them before editing.
**Scope:** `jahongirnewapp` — Laravel 11 + Filament v3 + Livewire 3.
**Last updated:** 2026-04-20

---

## Why this document exists

We hit real drift bugs — `isDispatchable` was encoded in two places and they silently diverged (slideover allowed assign, dispatch refused it). As the calendar, dispatch pipeline, bots, and CRM keep growing, code that doesn't respect layer boundaries becomes impossible to refactor safely. These rules exist to prevent that.

Read this before:
- Adding a new Filament action / page / resource
- Adding a new controller or endpoint
- Writing a new Service/Action
- Making any change > 50 LOC

---

## The six layers

```
┌─────────────────────────────────────────────────────────────┐
│  1. PRESENTATION    Filament pages, resources, Blade views   │
│                     Partials, component helpers              │
├─────────────────────────────────────────────────────────────┤
│  2. HTTP EDGE       Controllers, Middleware, Form Requests   │
│                     Route-specific policies                  │
├─────────────────────────────────────────────────────────────┤
│  3. BUSINESS LOGIC  Actions, Services, Domain helpers        │
│                     (app/Actions, app/Services)              │
├─────────────────────────────────────────────────────────────┤
│  4. DOMAIN DATA     Eloquent models, Value objects, Enums    │
│                     (app/Models, app/Support/*)              │
├─────────────────────────────────────────────────────────────┤
│  5. INFRASTRUCTURE  External-system adapters                 │
│                     TgDirectClient, Beds24Client, OctoClient │
├─────────────────────────────────────────────────────────────┤
│  6. ORCHESTRATION   Jobs, Listeners, Console commands        │
│                     Schedulers                               │
└─────────────────────────────────────────────────────────────┘
```

**Dependency direction**: top → bottom only.
A Model never calls a Filament page. A Service never renders Blade. A Controller never writes SQL.

---

## The 11 principles

### 1. Presentation stays in Filament

Filament pages, resources, Blade views, and partials render UI. They do not compute business rules.

**❌ Bad** — blade calling a query:
```blade
@php
    $paidAmount = \App\Models\SupplierPayment::forSupplier(...)->sum('amount');
@endphp
```

**✅ Good** — data prepared in the page or builder, passed as a view variable:
```blade
{{ $stay->amount_paid_formatted }}
```

### 2. Data stays in Models

Schema, casts, relationships, scopes, and simple attribute accessors (`getFullNameAttribute`, `getIsDispatchable`) live on models. No SQL spread across Services or controllers.

**Models may contain:**
- `$fillable`, `$casts`, `$hidden`
- Relationships (`hasMany`, `belongsTo`)
- Scopes (`scopeActive`, `scopeBetween`)
- Simple derived accessors (`full_name`, `amount_due`)
- Single-record boolean helpers (`isDispatchable()`, `isPaid()`)

**Models must NOT contain:**
- Multi-record aggregate queries with business rules (put in a Service)
- Telegram / HTTP / email sending (put in an Infrastructure adapter)
- Filament-specific formatting (put in a presentation helper)

### 3. Business logic in Actions & Services

**Actions** — single-purpose classes with one public method. Example: `DispatchDriver`, `ConfirmInquiryPayment`, `AssignSupplier`.

**Services** — long-lived components that own a capability. Example: `DriverDispatchNotifier`, `TourCalendarBuilder`, `TgDirectClient`.

Actions and Services must be:
- **Reusable** — no Filament/Request coupling
- **Pure** — side effects go through Events or Infrastructure adapters
- **Testable** — constructor injection, no static state

### 4. Controllers are thin

A controller method reads input, calls ONE action, returns a response. **Target: < 20 lines per method.** If it's longer, the body belongs elsewhere.

### 5. Single source of truth for business rules

Any business rule must live in exactly ONE place. Helpers on models are the default location; Actions own workflow rules.

**Example from this repo:** `BookingInquiry::isDispatchable()` replaces three separate `$status === CONFIRMED` checks that had drifted apart.

### 6. No queries in Blade

A `@php $x = Model::query()->...` block in a Blade file is instant refactor-bait. Prepare data in the page class (Filament) or controller, pass to view as props.

### 7. Adapter classes own external systems

All Telegram calls → `TgDirectClient`.
All Beds24 calls → one Beds24 client.
All Octobank → `OctoClient`.

**Rationale:** when the outside world changes (API deprecates, auth flow changes), we touch one file.

### 8. Events for cross-cutting side effects

"When a dispatch succeeds → stamp timestamp + log + notify operator" = events + listeners, not inline blocks. Use Laravel's event system.

### 9. Jobs / Commands orchestrate, don't implement

A `php artisan tg:backfill-dispatch-timestamps` command is ~30 lines that loop, print progress, and call an Action. The real work lives in the Action.

### 10. Validation at boundaries

- **HTTP input** → `FormRequest::rules()`
- **Service input** → validated DTO or value object (not raw arrays)

Never re-validate after the boundary. Trust internal types.

### 11. No hidden business logic in UI closures

Filament Actions must call Actions (classes), never embed logic inside `->action(function () { ... 60 LOC ... })` closures. If a closure grows past ~10 lines, extract it to `app/Actions/<Feature>/<ActionName>.php`.

**This is where Laravel apps rot fastest.** Closures grow silently, become untestable, and duplicate across pages.

**❌ Bad** — logic buried in closure:
```php
->action(function () use ($inquiry): void {
    $result = app(DriverDispatchNotifier::class)->dispatchSupplier($inquiry, 'driver');
    $stamp = now()->format('Y-m-d H:i');
    $name = $inquiry->driver?->full_name ?? 'driver';
    if ($result['ok']) {
        $inquiry->update([...]);
        Notification::make()->title(...)->success()->send();
    } else { ... }
})
```

**✅ Good** — closure calls an Action:
```php
->action(fn () => app(Actions\Calendar\DispatchDriverAction::class)->execute($inquiry))
```

---

## Operational timestamps — no mass-assign

**Rule:** any `*_sent_at`, `*_dispatched_at`, `*_paid_at`, `*_applied_at`,
`*_notified_at` write must use `forceFill([...])->save()` — never
`$model->update([...])` — unless the column is explicitly in `$fillable`.

**Why:** Eloquent silently drops mass-assigned attributes that aren't in
`$fillable`. Cron jobs that filter by `whereNull('xxx_sent_at')` then write
back via `update()` get stuck in an infinite retry loop and re-send guest
messages on every run.

**Production incidents this rule prevents:**
- 2026-04-26 hourly WhatsApp spam (status timestamps silently dropped)
- 2026-04-28 Alberto duplicate review WA (review_request_sent_at)
- INQ-2026-000015 hotel-request email sent 5 times (hotel_request_sent_at)

**Correct pattern:**
```php
$inquiry->forceFill(['review_request_sent_at' => now()])->save();
```

**Detection:** `scripts/arch-lint.sh` flags this as `P1
operational-timestamp-update`. Pre-push warns on it.

---

## Decision reference: where does this code go?

| If you're writing… | It belongs in… |
|---|---|
| A new column / accessor / scope | Model |
| A new business rule (one check or decision) | Model as a method, or Enum |
| A workflow that touches 2+ models or fires notifications | Action under `app/Actions/<Feature>/` |
| A capability used by 3+ callers | Service under `app/Services/` |
| A wrapper around an external HTTP API | Infrastructure adapter under `app/Services/*Client.php` |
| Display-only data preparation for a view | `*Builder` service (e.g. `TourCalendarBuilder`) |
| A scheduled task | Console command that calls an Action |
| Side effect on model change | Observer OR Listener |

---

## What each layer MUST NOT do

### Presentation (Blade / Filament)
- ❌ Query models (`Model::query()`, `DB::`)
- ❌ Send Telegram / email / HTTP
- ❌ Run multi-line `@php` blocks with business logic
- ❌ Duplicate logic that lives elsewhere

### HTTP Edge (Controllers)
- ❌ Exceed ~20 LOC per method
- ❌ Contain business rules
- ❌ Write SQL directly

### Business Logic (Actions / Services)
- ❌ Render Blade
- ❌ Access `Request`, `Session`, or `auth()` directly — accept arguments instead (auth-user-id passed in)
- ❌ Depend on Filament-specific classes

### Models
- ❌ Send notifications
- ❌ Call external APIs
- ❌ Contain workflow logic (multi-step)

---

## Enforcement

1. **Review checklist** — `docs/architecture/LAYER_CHEAT_SHEET.md`. Scan before merging.
2. **Lint** — `scripts/arch-lint.sh` runs in pre-push. Catches grep-able violations.
3. **Code review** — this file is the authority during disagreements.

Violations are ranked:
- **P0** (blocking): query in Blade, business rule duplicated in 2+ places, external API call outside adapter.
- **P1** (must-fix-next-PR): fat controller, closure > 10 LOC in Filament action.
- **P2** (backlog): Blade > 300 LOC, `@php` blocks with > 5 lines.

---

## How this document changes

A principle only changes if we find a real case where following it makes code WORSE. If that happens, open a PR updating this doc with:
1. The specific case
2. The alternative rule
3. How the lint script must be updated

Don't route around the document silently.
