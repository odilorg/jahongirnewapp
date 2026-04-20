#!/usr/bin/env bash
# install-git-hooks.sh — symlinks the repo's versioned hooks into .git/hooks.
# Run once after cloning (or when hooks change). Idempotent.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

HOOKS_SRC="scripts/git-hooks"
HOOKS_DST=".git/hooks"

if [ ! -d "$HOOKS_SRC" ]; then
    echo "No hooks source: $HOOKS_SRC" >&2
    exit 1
fi

for hook in "$HOOKS_SRC"/*; do
    name="$(basename "$hook")"
    chmod +x "$hook"
    ln -sf "../../$hook" "$HOOKS_DST/$name"
    echo "  linked $name"
done

echo "Git hooks installed into $HOOKS_DST (symlinked to $HOOKS_SRC)."
