# ADR: Replace per-message himalaya shell-out with a single persistent PHP IMAP driver

- **Status:** Proposed (NOT accepted, NOT implemented)
- **Date:** 2026-05-16
- **Owner:** pending
- **Supersedes:** the interim retry-net (commit 9fb10a2, reverted by c8a940d — see "Why interim failed")

## Context

`viator:fetch-emails` and `gyg:fetch-emails` ingest booking emails by spawning the
`himalaya` CLI **once per message** (`himalaya message read`). Each spawn is a fresh
Gmail IMAP LOGIN. himalaya v1.2.0 `message read` intermittently HANGS >60s on certain
Gmail FETCH responses (its imap-codec "Rectified missing `text`" path). Reproduced
2026-05-16: 7/8 reads ~1s, 1/8 hangs 65s; the same envelope can hang then succeed
(intermittent). This degraded Viator ingestion resilience since ~2026-05-14 (no data
loss confirmed — there were simply no new Viator bookings in the window).

### Why the interim retry-net failed (load-bearing lesson)

An interim retry-net (60s timeout → kill → retry once → mark needs_review → continue)
was implemented, tested (8 green), reviewed, deployed (9fb10a2) — and **caused a
production regression**: `proc_open` "Unable to launch a new process" on the scheduled
`viator:fetch-emails` (default `--limit=50`). Best-supported hypothesis (not red-handed
proven): the retry **doubles the process-seconds** each hung himalaya occupies; with
the */10 scheduler at limit=50, hung himalaya processes owned by `www-data` overlap
across runs and exhaust `www-data`'s per-UID fork capacity, so the *next* run's very
first `proc_open` (in the unchanged `listViatorEnvelopes`) fails. Server-wide bash/root
forking stayed fine; it cleared between overlap windows. **Rolled back to c2ab9552.**

**Conclusion: any approach that keeps the per-message process+IMAP-LOGIN model is
fragile. Blind retry makes it worse. The fix must remove per-message processes.**

## Decision (proposed)

Introduce a single-connection `MailboxClient` Infrastructure adapter
(`app/Services/Mail/*`, layer 5) using **webklex/php-imap ^6.2 (already in
composer.json)** behind an `INBOUND_MAIL_DRIVER` config flag (`himalaya` | `imap` |
`compare`), with a himalaya shim implementing the same interface for rollback. One
persistent IMAP connection per run; fetch raw MIME in-process; parse in PHP; write the
**identical** normalized rows so every downstream processor
(`viator:apply-new-bookings`, `gyg:process-emails`, parsers) is **unchanged**.

Non-negotiable invariants:
- **Zero schema change.** Same idempotency keys (`gmail_message_id` /
  `email_message_id` + synthetic fallbacks); never key on IMAP UID (UIDVALIDITY).
- **Preserve GYG body-fail contract exactly**: body fetch failure still stores a
  `processing_status='fetched'` row so `gyg:process-emails` alerts (incidents
  GYG48YVRXWBH 2026-04-27, 2026-05-05 audit) — never a silent drop.
- **Behavior parity = parser-output parity** (raw MIME vs himalaya text-dump differ
  inherently); proven by real sanitized `.eml` fixtures.
- Risk tier **HIGH** (booking/money path): architect plan (this ADR) → implementation-
  planner staged plan → code-reviewer deep mode → real-sample tests → `compare`-mode
  production diff → Viator cutover → GYG cutover; himalaya retained as flag-only
  rollback ≥2 weeks.

## Phases (proposed, plan-only)

- **P1** `config/inbound_mail.php` + `MailboxClient` interface + DTOs +
  `FakeMailboxClient` + sanitized `.eml` fixtures.
- **P2** `HimalayaMailboxClient` shim behind flag; behavior-parity tests green on the
  existing himalaya path (default driver unchanged).
- **P3** `ImapMailboxClient` (webklex) + extract `IngestGygEmailsAction`,
  `IngestViatorEmailsAction`, `ViatorDiffBuilder`; full test matrix (success,
  malformed MIME, large HTML, timeout, duplicate, UIDVALIDITY reset, attachment,
  missing Message-ID; BR-1393592315-class regression).
- **P4** `compare` mode in production ≥48h (IMAP computes would-be keys/outcomes,
  himalaya stays the writer) — diff key parity + GYG body-fail parity.
- **P5** staged cutover (Viator first, then GYG) + rollback drill.

Rollback at every step = flip `INBOUND_MAIL_DRIVER`, no deploy, no migration.

## Consequences

- Removes the per-message subprocess + repeated IMAP LOGIN (the actual root cause and
  the interim's failure mode).
- Larger change than the interim, but reversible and downstream-invisible.
- DB backup before any rollout step (repo rule) even though no schema change —
  `compare`/parallel still exercises code paths.

## Open questions

- Confirm himalaya `gmail` account uses an app password (assumed) vs OAuth2 — if
  OAuth2, P3 must add an XOAUTH2 auth strategy (interface reserves the seam).
- `body_html` capture for GYG: out of scope (separate behavior change).
