# AUDIT_BRIEF — Jahongir Hotel Operations System

**Status:** Active
**Created:** 2026-04-18
**Phase:** 0 (Setup complete) → Phase 1 (Inventory) next
**Companion docs:** `.claude/PROJECT_CONTEXT.md` (system map) · `docs/architecture/*` (audit artifacts)

---

## 1. Mission

This is **not** a Laravel CRUD app. It is evolving into an **internal operating system for two hotels**:

- **Jahongir Guest House**
- **Jahongir Premium Hotel**

Every architectural decision must serve one of four outcomes:

1. **Money control** — cash, card, transfers, expenses, reconciliation, zero ambiguity
2. **Operational coordination** — admin ↔ cleaners, admin ↔ kitchen, reception ↔ management
3. **Operational visibility** — room states, tasks, issues, daily flow clarity
4. **Owner-level reporting** — daily/weekly/monthly, profit truth, decision visibility

If a change does not improve money control, operational clarity, or decision visibility → it is noise. Reject it.

---

## 2. Target architecture — strict 3-layer

### Presentation
`app/Http/Controllers`, `app/Livewire`, `app/Filament`, Telegram/webhook entrypoints, API routes

**Responsibilities:** validate input → call Application → return response
**MUST NOT:** contain business logic · query the database directly · calculate money · orchestrate workflows

### Application
`app/Services`, `app/Actions`, `app/Jobs`, `app/DTOs`

**Responsibilities:** business logic · workflow orchestration · use-case execution
**Rules:**
- **Actions** = one use-case each (e.g. `RecordCashPaymentAction`)
- **Services** = multi-step workflows that may invoke multiple actions
- **NO** direct HTTP / `request()` / `session()` / `auth()->user()` inside services or actions — pass the data in

### Data
`app/Models` (Eloquent), `database/migrations`, relationships, casts

**Responsibilities:** data structure, relationships, persistence
**MUST NOT:** contain business rules · workflow logic · money calculations

---

## 3. Strategic cores (non-negotiable direction)

The system must converge on two foundational cores. Every new feature aligns with one of them — otherwise it is scope creep.

### 3.1 Ledger-based financial core
All money movement must be **explicit · traceable · immutable · reconstructable**.

Every financial event is an append-only ledger entry:
- cash in · cash out · card payment · bank transfer · refund
- expense · drawer open/close · shift handoff · reconciliation adjustment

**No scattered `$amount -= x` across services.** All money logic flows through one layer.

### 3.2 Task-based operational core
Staff coordination is modeled as **tasks with assignments, statuses, events** — not as ad-hoc Telegram messages or hardcoded bot flows.

Examples: cleaning task · room prep · maintenance · kitchen request · guest special request · follow-up.

Reusable entity: `Task(assignee, status, type, context, events[], comments[])` — shared across housekeeping, kitchen, maintenance, reception.

---

## 4. Non-negotiable constraints

Enforced on every session, every file, every commit:

1. **Controllers** orchestrate only — no Eloquent queries, no money math, no workflow logic
2. **Actions** = one use-case — no god-actions
3. **Services** = workflow orchestration — no HTTP request coupling
4. **Models** = data only — no business rules
5. **Money logic centralized** — one layer, one vocabulary, no duplication
6. **No silent coupling** — use DI, not `new ServiceB()` inside Service A
7. **Every change traceable to one of the 4 core objectives** (§1)

---

## 5. Audit methodology

**Phases 1–3 are read-only.** No refactor until Phase 5 is approved.

| Phase | Artifact | Scope |
|---|---|---|
| 0 | `.claude/AUDIT_BRIEF.md` (this file) | Setup, parity check |
| 1 | `docs/architecture/INVENTORY.md` · `ROUTES_AND_ENTRYPOINTS.md` | LOC, structures, routes, entrypoints |
| 2 | `docs/architecture/DOMAINS.md` | Bounded contexts, cross-domain deps |
| 3 | `docs/architecture/VIOLATIONS.md` | Layer leaks, god-classes, dupes — `file:line` precision |
| 3.5 | `docs/architecture/MONEY_FLOW_DEEP_DIVE.md` | Money entry/mutation/calc trace — **priority domain** |
| 4 | `docs/architecture/TARGET_ARCHITECTURE.md` | Ledger core, task core, clean layer design |
| 5 | `docs/architecture/REFACTOR_PLAN.md` · `FEATURE_BACKLOG.md` | Prioritized backlog |

**Evidence standard:** every violation cites `path/to/file.php:LINE` — no vague "controllers are fat".

---

## 6. Hard rules for every session

1. ❌ **No code changes** during Phases 1–3
2. ❌ **No vague findings** — always `file:line`
3. ❌ **No premature solutions** — understand current system first
4. ❌ **No feature implementation** until refactor plan is approved
5. ✅ **Edit locally** in `~/projects/jahongirnewapp`, never on VPS (per user standing rule)
6. ✅ **All artifacts land in `docs/architecture/`**
7. ✅ **Feature ideas go to `FEATURE_BACKLOG.md`** — never mixed into current-state docs
8. ✅ **Commit early, commit often** — one atomic commit per artifact, push immediately
9. 🔒 **Tour runtime is PROTECTED** — jahongirnewapp is a shared Laravel app serving BOTH hotel ops AND live tour operations. This refactor is a hotel-core refactor inside a shared app, not an app-wide refactor. Every ticket must pass the scope gate in `docs/architecture/SCOPE_GATE.md` — domain / runtime impact / tour risk / safety proof — before execution. A ticket proceeds only if one of: (a) hotel-only in runtime, (b) purely additive, (c) behind hotel-only flag/route/panel/namespace, (d) has regression test proving tour flows unchanged. (Added 2026-04-18 per user directive.)
10. 💾 **Full verified DB backup before ANY schema/migration/bulk-data change** — no exceptions, even for "zero-risk" changes. Target: `/var/backups/databases/daily/<YYYYMMDD_HHMMSS>_jahongirnewapp_<context>.sql.gz`. (Added 2026-04-18 per user directive.)

---

## 7. Operating mode

You are a **Senior Software Architect**, not a coder. Be:

- **Precise** — facts with file:line
- **Structured** — tables, bullet lists, headers
- **Critical** — call out god-classes, duplicated logic, leaky layers
- **Evidence-driven** — no opinions without citations
- **Resistant to premature coding** — stay in analysis mode

---

## 8. Repo state baseline (Phase 0)

| Item | Value |
|---|---|
| Local clone | `~/projects/jahongirnewapp` |
| Branch | `main` |
| HEAD | `f1a5b73` — fix(calendar): show contacted + awaiting_customer leads |
| VPS clone | `/var/www/jahongirnewapp` @ `f1a5b73` (detached HEAD, in sync) |
| Remote | `github.com/odilorg/jahongirnewapp.git` |
| Stack | Laravel 11 · Filament 3 · Livewire · MySQL · Redis · Laravel Queues |

---

## 9. Related references

- `.claude/PROJECT_CONTEXT.md` — 16-feature-block system map (last updated 2026-03-27)
- `.claude/checkin-checkout-plan.md` — prior design note
- User global rules — `~/.claude/rules/` (git, deployment, testing, debugging)

---

**Next action:** begin Phase 1 — write `docs/architecture/INVENTORY.md` and `ROUTES_AND_ENTRYPOINTS.md`.
