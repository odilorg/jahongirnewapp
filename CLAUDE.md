# jahongirnewapp — Claude Guidance

## MUST READ before any non-trivial change

1. **`docs/architecture/PRINCIPLES.md`** — the six-layer architecture + 11 principles. Authoritative.
2. **`docs/architecture/LAYER_CHEAT_SHEET.md`** — one-page quick reference for "where does this code go?"

Violating these rules is a merge-blocker. The pre-push hook runs `scripts/arch-lint.sh` against `scripts/arch-lint-baseline.txt` — NEW violations fail the push.

## Workflow

- **Local-first**: implement + verify locally; summarize; wait for explicit approval before deploying.
- **Deploy only via `scripts/deploy-production.sh <commit-sha>`** (see `feedback_production_deploy_discipline`).
- **Feature branches** for risky / hard-rollback / multi-subsystem changes. Otherwise direct-to-main + deploy is fine (see `feedback_jahongirnewapp_git_workflow`).
- **Fix log**: after any production bugfix on jahongir-app.uz, append an entry to the fix log (see `feedback_fixed_bugs_protocol`).

## Hard lines — never cross

- No `Model::query()` / `DB::` in Blade.
- No Telegram / Beds24 / external HTTP outside `app/Services/*Client.php` (webhook controllers are the one exception).
- No business logic embedded inside Filament `->action(function () { … })` closures past ~10 LOC — extract to `app/Actions/<Feature>/`.
- No duplicated business rule. Put it on the model as a method or in an Action.
- No `view:cache` / `config:cache` run as root over SSH — always `sudo -u www-data`.

## Tooling reference

```bash
scripts/arch-lint.sh                    # full scan
scripts/arch-lint.sh --staged           # only staged files
scripts/arch-lint.sh --warn             # never blocks, informational
scripts/arch-lint.sh --regen-baseline > scripts/arch-lint-baseline.txt
scripts/install-git-hooks.sh            # re-run if hooks change
```
