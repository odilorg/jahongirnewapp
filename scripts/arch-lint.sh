#!/usr/bin/env bash
# arch-lint.sh — grep-based architecture guard
#
# Runs a handful of fast checks against the six-layer rules defined in
# docs/architecture/PRINCIPLES.md. Designed for pre-push; exits non-zero
# if any P0 or P1 violation is found.
#
# Usage:
#   scripts/arch-lint.sh            # full scan, fail on P0/P1
#   scripts/arch-lint.sh --warn     # same scan, never fail (for CI warn-mode)
#   scripts/arch-lint.sh --staged   # only files added/modified in the index
#
# Exit codes:
#   0 — clean
#   1 — P0 or P1 violation (unless --warn)
#   2 — tooling error (missing git etc.)

set -euo pipefail

MODE="strict"
SCOPE="all"
BASELINE=""
REGEN_BASELINE=0

for arg in "$@"; do
    case "$arg" in
        --warn)    MODE="warn" ;;
        --staged)  SCOPE="staged" ;;
        --baseline=*)  BASELINE="${arg#--baseline=}" ;;
        --regen-baseline) REGEN_BASELINE=1; MODE="warn" ;;
        --help|-h)
            sed -n '2,20p' "$0"
            exit 0
            ;;
    esac
done

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# --- colors (only when a TTY) --------------------------------------------------
if [ -t 1 ]; then
    RED=$'\033[31m'; YELLOW=$'\033[33m'; GREEN=$'\033[32m'; GRAY=$'\033[90m'; OFF=$'\033[0m'
else
    RED=""; YELLOW=""; GREEN=""; GRAY=""; OFF=""
fi

p0=0  # hard violations (new)
p1=0  # soft violations (new)
p2=0  # cosmetic — informational
baselined=0  # existing violations from baseline

# Load baseline signatures (one per line: "file:line:rule-key")
declare -A BASELINE_SET
if [ -n "$BASELINE" ] && [ -f "$BASELINE" ]; then
    while IFS= read -r sig; do
        [ -z "$sig" ] && continue
        [[ "$sig" =~ ^# ]] && continue
        BASELINE_SET["$sig"]=1
    done < "$BASELINE"
fi

# For --regen-baseline, collect new signatures to stdout-TMP
REGEN_BUFFER=""

report() {
    local sev="$1" rule_key="$2" rule_label="$3" file="$4" line="${5:-}" snip="${6:-}"
    local sig="$file:$line:$rule_key"

    if [ "$REGEN_BASELINE" -eq 1 ]; then
        REGEN_BUFFER+="$sig"$'\n'
        return
    fi

    if [ -n "${BASELINE_SET[$sig]:-}" ]; then
        baselined=$((baselined+1))
        return
    fi

    case "$sev" in
        P0) color="$RED";    p0=$((p0+1)) ;;
        P1) color="$YELLOW"; p1=$((p1+1)) ;;
        P2) color="$GRAY";   p2=$((p2+1)) ;;
    esac
    if [ -n "$line" ]; then
        printf '%s[%s]%s %s\n    %s:%s\n    %s\n' "$color" "$sev" "$OFF" "$rule_label" "$file" "$line" "$snip"
    else
        printf '%s[%s]%s %s\n    %s\n' "$color" "$sev" "$OFF" "$rule_label" "$file"
    fi
}

# --- pick the file list --------------------------------------------------------
if [ "$SCOPE" = "staged" ]; then
    mapfile -t PHP_FILES < <(git diff --cached --name-only --diff-filter=ACMR -- '*.php' 2>/dev/null || true)
    mapfile -t BLADE_FILES < <(git diff --cached --name-only --diff-filter=ACMR -- '*.blade.php' 2>/dev/null || true)
else
    mapfile -t PHP_FILES < <(find app -name '*.php' 2>/dev/null | grep -v '\.blade\.php$')
    mapfile -t BLADE_FILES < <(find resources/views -name '*.blade.php' 2>/dev/null)
fi

# --- P0: queries in Blade ------------------------------------------------------
for f in "${BLADE_FILES[@]}"; do
    [ -f "$f" ] || continue
    # Known-safe exceptions (pagination helpers, filament internal).
    # Pattern matches Model::query(), DB::table, DB::select, DB::statement.
    while IFS=: read -r line content; do
        report "P0" "blade-query" "Query in Blade (rule 6)" "$f" "$line" "${content#[[:space:]]*}"
    done < <(grep -nE '\\?\b(Model|[A-Z][A-Za-z0-9_]+)::(query|all|where|find|first|count|sum|pluck|get|exists)\(|DB::(table|select|statement|raw)\(|\bHttp::(get|post|put|delete)\(' "$f" 2>/dev/null | head -20 || true)
done

# --- P0: external HTTP outside adapter -----------------------------------------
for f in "${PHP_FILES[@]}"; do
    [ -f "$f" ] || continue
    # Adapters are app/Services/*Client.php — allow Http:: there.
    case "$f" in
        app/Services/*Client.php) continue ;;
        app/Http/Controllers/Webhook*|app/Http/Controllers/*CallbackController.php)
            # Webhook/callback controllers legitimately reply to external systems
            continue ;;
    esac
    while IFS=: read -r line content; do
        # Skip comments
        echo "${content#[[:space:]]*}" | grep -qE '^\s*//|^\s*\*|^\s*#' && continue
        report "P0" "http-outside-adapter" "HTTP outside adapter (rule 7)" "$f" "$line" "${content#[[:space:]]*}"
    done < <(grep -nE '\bHttp::(get|post|put|delete|timeout)\(' "$f" 2>/dev/null | head -5 || true)
done

# --- P1: long Filament action closures -----------------------------------------
# Heuristic: lines starting with "->action(function" and counting until the next
# "}) ;" or "});" at same indent. If > 12 logical lines, flag.
for f in "${PHP_FILES[@]}"; do
    [ -f "$f" ] || continue
    case "$f" in
        app/Filament/*) ;;
        *) continue ;;
    esac
    while read -r line_info; do
        start_line="${line_info%%:*}"
        rest="${line_info#*:}"
        loc="${rest%% *}"
        report "P1" "long-action-closure" "Long action closure — extract to Action (rule 11)" "$f" "$start_line" "$loc lines"
    done < <(awk '
        /->action\(function/ { start=NR; brace=1; depth=1; next }
        brace==1 {
            depth += gsub(/[({]/,"&") - gsub(/[)}]/,"&")
            if (depth <= 0) {
                if (NR-start > 12) print start":"NR-start" line action closure"
                brace=0
            }
        }' "$f")
done

# --- P1: fat controller methods ------------------------------------------------
for f in "${PHP_FILES[@]}"; do
    [ -f "$f" ] || continue
    case "$f" in
        app/Http/Controllers/*.php) ;;
        *) continue ;;
    esac
    while IFS=: read -r line loc name; do
        report "P1" "fat-controller" "Fat controller method (rule 4)" "$f" "$line" "$loc lines: ${name#[[:space:]]*}"
    done < <(awk '
        /^    public function/ { start=NR; name=$0; brace=0; capturing=1 }
        capturing {
            brace += gsub(/\{/,"&") - gsub(/\}/,"&")
            if (brace > 0 && first == 0) first = NR
            if (brace == 0 && first > 0) {
                loc = NR - start
                if (loc > 25) print start":"loc":"name
                capturing=0; first=0
            }
        }' "$f")
done

# --- P2: long @php blocks in Blade --------------------------------------------
for f in "${BLADE_FILES[@]}"; do
    [ -f "$f" ] || continue
    while IFS=: read -r line loc; do
        report "P2" "long-php-block" "Long @php block — move to builder (rule 1)" "$f" "$line" "$loc lines"
    done < <(awk '
        /@php/ { start=NR; inside=1; next }
        /@endphp/ && inside {
            if (NR-start > 10) print start":"NR-start
            inside=0
        }' "$f")
done

# --- P2: big Blade files -------------------------------------------------------
for f in "${BLADE_FILES[@]}"; do
    [ -f "$f" ] || continue
    lines=$(wc -l < "$f")
    if [ "$lines" -gt 400 ]; then
        report "P2" "large-blade" "Large Blade (>400 lines)" "$f" "" "$lines lines — consider splitting"
    fi
done

# --- summary -------------------------------------------------------------------
if [ "$REGEN_BASELINE" -eq 1 ]; then
    # Print sorted, deduped signatures — user pipes to baseline file
    printf '# arch-lint baseline — regenerated %s\n' "$(date -Iseconds)"
    printf '# Format: file:line:rule-key (rule-key stable across runs)\n'
    printf '%s' "$REGEN_BUFFER" | sort -u | grep -v '^$' || true
    exit 0
fi

echo
printf '%sarch-lint:%s new-P0=%d  new-P1=%d  P2=%d  (baselined=%d)\n' "$GREEN" "$OFF" "$p0" "$p1" "$p2" "$baselined"

if [ "$MODE" = "warn" ]; then
    exit 0
fi

if [ "$p0" -gt 0 ] || [ "$p1" -gt 0 ]; then
    echo "${RED}→ Blocking NEW violations (not in baseline). Fix, or move into baseline with:${OFF}"
    echo "  ${GRAY}scripts/arch-lint.sh --regen-baseline > scripts/arch-lint-baseline.txt${OFF}"
    exit 1
fi

exit 0
