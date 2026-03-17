#!/usr/bin/env bash
set -Eeuo pipefail

# ── Production Deploy Script ────────────────────────────────
# Usage: ./scripts/deploy-production.sh <tag-or-commit>
# Example: ./scripts/deploy-production.sh release-2026-03-17-gyg-01
#
# Rules:
# - Only deploy tags or commit SHAs, never branch names
# - This is the ONLY way to deploy to production
# - No manual git operations in the app directory
# ─────────────────────────────────────────────────────────────

APP_DIR="/var/www/jahongirnewapp"
RELEASE_REF="${1:?Usage: deploy-production.sh <tag-or-commit>}"
LOG_FILE="/var/log/jahongirnewapp-deploy.log"
DEPLOY_HISTORY="$APP_DIR/.deploy-history"
OPERATOR="${SUDO_USER:-${USER:-unknown}}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

# ── Ref validation ──────────────────────────────────────────
# Accept only: release-* tags, full 40-char SHAs, short SHAs (7+ hex chars)
# Reject branch names outright.
is_release_tag()  { [[ "$1" =~ ^release- ]]; }
is_full_sha()     { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
is_short_sha()    { [[ "$1" =~ ^[0-9a-f]{7,39}$ ]]; }

if ! is_release_tag "$RELEASE_REF" && ! is_full_sha "$RELEASE_REF" && ! is_short_sha "$RELEASE_REF"; then
    echo "ERROR: '$RELEASE_REF' looks like a branch name or unsupported ref." >&2
    echo "       Only release-* tags or commit SHAs (7–40 hex chars) are allowed." >&2
    echo "       Use: git tag release-YYYY-MM-DD-description && git push --tags" >&2
    exit 1
fi

log "==> Starting deploy: $RELEASE_REF"

# ── Step 1: Fetch code ──────────────────────────────────────
cd "$APP_DIR"
log "==> Fetching from origin"
git fetch --prune --tags origin

# Verify the ref exists
if ! git rev-parse --verify "$RELEASE_REF" >/dev/null 2>&1; then
    log "ERROR: ref '$RELEASE_REF' not found"
    exit 1
fi

# ── Step 2: Clean checkout ──────────────────────────────────
log "==> Checking out $RELEASE_REF"
git checkout --force "$RELEASE_REF"
git reset --hard "$RELEASE_REF"
git clean -fd

DEPLOYED_SHA=$(git rev-parse HEAD)
DEPLOYED_TAG=$(git describe --tags --exact-match 2>/dev/null || echo "no-tag")
DEPLOYED_AT=$(date '+%Y-%m-%d %H:%M:%S')
log "==> Deployed: $DEPLOYED_SHA ($DEPLOYED_TAG)"

# ── Write .version file ─────────────────────────────────────
# Read by the /healthz endpoint so it never needs to shell out.
cat > "$APP_DIR/.version" <<EOF
SHA=$DEPLOYED_SHA
TAG=$DEPLOYED_TAG
DEPLOYED_AT=$DEPLOYED_AT
EOF
log "==> Wrote .version (sha=${DEPLOYED_SHA:0:8}, tag=$DEPLOYED_TAG)"

# ── Step 3: Dependencies ────────────────────────────────────
log "==> Installing dependencies"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction 2>&1 | tail -3

# ── Step 4: Laravel optimize ────────────────────────────────
log "==> Running Laravel cache/optimize"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── Step 5: Migrations ──────────────────────────────────────
log "==> Running migrations (if any)"
php artisan migrate --force --no-interaction 2>&1 | tail -5

# ── Step 6: Restart services ────────────────────────────────
log "==> Restarting services"

# PHP-FPM (if running)
if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
    systemctl reload php8.3-fpm
    log "    PHP-FPM reloaded"
elif systemctl is-active --quiet php8.2-fpm 2>/dev/null; then
    systemctl reload php8.2-fpm
    log "    PHP-FPM reloaded"
fi

# PM2 workers
if command -v pm2 &>/dev/null; then
    pm2 restart all --update-env 2>&1 | tail -5
    log "    PM2 restarted"
fi

# Queue workers
php artisan queue:restart 2>/dev/null || true
log "    Queue workers signaled to restart"

# ── Step 7: Health checks ───────────────────────────────────
# Disable exit-on-error for health checks — failures are logged, not fatal
set +e
log "==> Running health checks"

CHECKS_PASSED=0
CHECKS_TOTAL=3

# Check 1: DB connection
if php artisan tinker --execute="DB::select('select 1');" >/dev/null 2>&1; then
    log "    ✅ DB connection OK"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ DB connection FAILED"
fi

# Check 2: Current commit matches intended
LIVE_SHA=$(git rev-parse HEAD)
if [ "$LIVE_SHA" = "$DEPLOYED_SHA" ]; then
    log "    ✅ Commit matches: ${LIVE_SHA:0:8}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ Commit mismatch: expected $DEPLOYED_SHA, got $LIVE_SHA"
fi

# Check 3: PM2 processes online
if command -v pm2 &>/dev/null; then
    PM2_ONLINE=$(pm2 jlist 2>/dev/null | grep -c '"status":"online"' || true)
    if [ "$PM2_ONLINE" -gt 0 ] 2>/dev/null; then
        log "    ✅ PM2: $PM2_ONLINE process(es) online"
        CHECKS_PASSED=$((CHECKS_PASSED + 1))
    else
        log "    ❌ PM2: no online processes"
    fi
else
    log "    ⏭ PM2 not installed, skipping"
    ((CHECKS_PASSED++))
fi

set -e
# ── Summary ─────────────────────────────────────────────────
log "==> Deploy complete: $RELEASE_REF ($DEPLOYED_SHA)"
log "    Health: $CHECKS_PASSED/$CHECKS_TOTAL checks passed"
log "    Tag: $DEPLOYED_TAG"

if [ "$CHECKS_PASSED" -lt "$CHECKS_TOTAL" ]; then
    log "⚠️  DEPLOY COMPLETED WITH WARNINGS — check health failures above"
    # Still record the deploy attempt in history even on partial success
    echo "${DEPLOYED_AT} | ${DEPLOYED_TAG} | ${DEPLOYED_SHA:0:8} | ${OPERATOR}" >> "$DEPLOY_HISTORY"
    exit 1
fi

# ── Record deploy history ───────────────────────────────────
echo "${DEPLOYED_AT} | ${DEPLOYED_TAG} | ${DEPLOYED_SHA:0:8} | ${OPERATOR}" >> "$DEPLOY_HISTORY"

log "✅ Deploy successful"
log ""
log "── Last 5 deploys ──────────────────────────────────────"
tail -5 "$DEPLOY_HISTORY" | while IFS= read -r line; do log "    $line"; done
log "────────────────────────────────────────────────────────"
