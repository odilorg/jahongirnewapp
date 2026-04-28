# Layer Cheat Sheet

One-page quick reference. If you're about to write X in Y, scan this first.
Authority: `PRINCIPLES.md` — this is its TL;DR.

---

## If you're editing a **Blade view** (`resources/views/**/*.blade.php`)

**DO**
- Use variables passed to the view
- Use accessor methods on models (`$inquiry->is_dispatchable`)
- Format dates/money with helpers

**DON'T**
- `Model::query()`, `::where`, `::all`
- `DB::table`, `DB::select`
- `Http::`, Telegram calls, email sending
- Multi-line `@php` blocks with business rules
- Computed aggregates (sum, count, group) inside `@foreach`

---

## If you're editing a **Filament page** (`app/Filament/Pages/*.php`)

**DO**
- Define Livewire properties and route them to Action classes
- Use `modalContent(fn () => view(...))` to render partials
- Keep `->action()` closures as ≤ 10-line delegations

**DON'T**
- Embed business logic inside `->action(function () { ... })` closures
- Use `DB::`, raw SQL, direct HTTP calls
- Duplicate validation that belongs in FormRequest / Action

If a page method exceeds ~30 lines of non-schema code, stop and extract.

---

## If you're editing a **Filament resource** (`app/Filament/Resources/*.php`)

**DO**
- Configure form fields, table columns, action visibility
- Route table/row/bulk actions to Action classes
- Use `->visible(fn (Model $r) => $r->someHelper())` for model-owned gating

**DON'T**
- Hard-code same business rule inline if it exists on the model
- Put dispatch / notification logic in `->action()` closures

---

## If you're editing a **Controller** (`app/Http/Controllers/*.php`)

**DO**
- Read request, call one Action, return response
- Keep method body < 20 lines

**DON'T**
- Business logic
- Query models directly except the simplest `findOrFail`
- Validation — that belongs in FormRequest

---

## If you're writing an **Action** (`app/Actions/**/*.php`)

**Shape:**
```php
final class DispatchDriverAction
{
    public function __construct(private readonly DriverDispatchNotifier $notifier) {}

    public function execute(BookingInquiry $inquiry): DispatchResult
    {
        // business logic here
    }
}
```

**DO**
- One public method named `execute` or `handle`
- Accept domain objects (Model, DTO), not Request
- Return a DTO, not an array

**DON'T**
- Access `Request`, `Session`, `auth()` — take them as arguments
- Render views
- Depend on Filament classes

---

## If you're writing a **Service** (`app/Services/*.php`)

**Shape:** a long-lived capability, multiple callers, injected via DI.

**DO**
- Own a single external system (`*Client.php`)
- Own a single coherent capability (`TourCalendarBuilder`, `DriverDispatchNotifier`)
- Use constructor DI for dependencies

**DON'T**
- Access request state (pass it in)
- Be called from Blade views directly

---

## If you're editing a **Model** (`app/Models/*.php`)

**DO**
- Columns (`$fillable`, `$casts`), relationships, scopes
- Single-record helpers returning primitives or booleans (`isDispatchable()`)
- Simple accessors (`full_name`, `status_label`)

**DON'T**
- Multi-model workflow logic → Action
- External API calls → Adapter
- Notification sending → Service + Event

---

## If you're writing a **Console command** (`app/Console/Commands/*.php`)

**Body pattern:**
```php
public function handle(): int
{
    $records = Model::query()->...->get();
    foreach ($records as $r) {
        app(MyAction::class)->execute($r);
    }
    return self::SUCCESS;
}
```

**DO**
- Print progress, handle `--dry-run`, log summary
- Delegate real work to Actions

**DON'T**
- Implement business logic inline
- Duplicate what an Action already does

---

## Red-flag patterns — if you see these, refactor

| Pattern | Violation | Fix |
|---|---|---|
| `Model::query()` inside `resources/views/` | P0 — query in Blade | Prepare in page/controller |
| `Http::post(...)` outside `app/Services/` | P0 — external API leak | Put in `*Client.php` |
| Same `$status === X` check in 3+ files | P0 — rule drift | Extract to model helper |
| `->action(function () { …40 LOC… })` | P1 — hidden logic in closure | Extract to Action |
| Controller method > 25 LOC | P1 — fat controller | Delegate to Action |
| `@php …10+ lines… @endphp` in Blade | P2 — view logic | Move to builder service |
| Blade > 400 LOC | P2 — needs splitting | Break into partials |
| `$model->update(['xxx_sent_at' => …])` (or `_dispatched_at`/`_paid_at`/`_applied_at`/`_notified_at`) | P1 — silent-drop risk | `$model->forceFill([...])->save()` |

---

## Naming conventions

- **Actions:** `app/Actions/<Feature>/<Verb><Noun>Action.php` → `DispatchDriverAction`, `ConfirmPaymentAction`
- **Services:** `app/Services/<Capability>.php` → `TourCalendarBuilder`, `DriverDispatchNotifier`
- **Adapters:** `app/Services/<External>Client.php` → `TgDirectClient`, `Beds24Client`
- **DTOs:** `app/Support/<Context>/<Name>.php` (final readonly) → `SupplierRecipient`, `DispatchResult`
- **Form Requests:** `app/Http/Requests/<Action><Noun>Request.php` → `StoreInquiryRequest`
