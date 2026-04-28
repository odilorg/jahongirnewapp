#!/usr/bin/env bash
set -Eeuo pipefail

# ── Production Deploy Script (v2.1) ───────────────────────────
# Usage: ./scripts/deploy-production.sh <tag-or-commit>
# Example: ./scripts/deploy-production.sh release-2026-03-18-bugfix
#
# Rules:
# - Only deploy tags or commit SHAs, never branch names
# - This is the ONLY way to deploy to production
# - No manual git operations in the app directory
#
# v2 changes:
# - Error trap for better failure logging
# - Targeted PM2 restart (only this app's processes)
# - Correct ownership (www-data) on runtime dirs
# - Pre-migration sanity check
# - HTTP health check via /healthz
# - More log context on dependency install and migration
# ───────────────────────────────────────────────────────────────

APP_DIR="/var/www/jahongirnewapp"
RELEASE_REF="${1:?Usage: deploy-production.sh <tag-or-commit>}"
LOG_FILE="/var/log/jahongirnewapp-deploy.log"
DEPLOY_HISTORY="$APP_DIR/.deploy-history"
OPERATOR="${SUDO_USER:-${USER:-unknown}}"

# PM2 processes that belong to THIS app (not others on the server)
APP_PM2_PROCESSES="jahongir-queue"

# PHP-FPM runs as www-data; deploy runs as root
APP_USER="www-data"
APP_GROUP="www-data"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

# ── Error trap: log which command failed ──────────────────────
trap 'log "ERROR at line $LINENO: $BASH_COMMAND"' ERR

# ── Ref validation ────────────────────────────────────────────
is_release_tag()  { [[ "$1" =~ ^release- ]]; }
is_full_sha()     { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
is_short_sha()    { [[ "$1" =~ ^[0-9a-f]{7,39}$ ]]; }

if ! is_release_tag "$RELEASE_REF" && ! is_full_sha "$RELEASE_REF" && ! is_short_sha "$RELEASE_REF"; then
    echo "ERROR: '$RELEASE_REF' looks like a branch name or unsupported ref." >&2
    echo "       Only release-* tags or commit SHAs (7-40 hex chars) are allowed." >&2
    echo "       Use: git tag release-YYYY-MM-DD-description && git push --tags" >&2
    exit 1
fi

log "==> Starting deploy: $RELEASE_REF (operator: $OPERATOR)"

# ── Step 1: Fetch code ────────────────────────────────────────
cd "$APP_DIR"
log "==> Fetching from origin"
git fetch --prune --tags origin

if ! git rev-parse --verify "$RELEASE_REF" >/dev/null 2>&1; then
    log "ERROR: ref '$RELEASE_REF' not found after fetch"
    exit 1
fi

# ── Step 2: Clean checkout ────────────────────────────────────
log "==> Checking out $RELEASE_REF"
git checkout --force "$RELEASE_REF"
git reset --hard "$RELEASE_REF"
git clean -fd

# ── Step 3: Rebuild runtime directories ───────────────────────
# git clean -fd wipes untracked files including Laravel runtime dirs.
# These are runtime state, not code — recreated every deploy.
log "==> Rebuilding runtime directories"
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache
chown -R "$APP_USER:$APP_GROUP" storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

DEPLOYED_SHA=$(git rev-parse HEAD)
DEPLOYED_TAG=$(git describe --tags --exact-match 2>/dev/null || echo "no-tag")
DEPLOYED_AT=$(date '+%Y-%m-%d %H:%M:%S')
log "==> Deployed: $DEPLOYED_SHA ($DEPLOYED_TAG)"

# ── Write .version file ──────────────────────────────────────
cat > "$APP_DIR/.version" <<EOF
SHA=$DEPLOYED_SHA
TAG=$DEPLOYED_TAG
DEPLOYED_AT=$DEPLOYED_AT
EOF
log "==> Wrote .version (sha=${DEPLOYED_SHA:0:8}, tag=$DEPLOYED_TAG)"

# ── Preflight: .env exists ─────────────────────────────────────
if [ ! -f .env ]; then
    log "ERROR: .env file missing — cannot continue"
    exit 1
fi

# ── Step 4: Dependencies ─────────────────────────────────────
log "==> Installing dependencies"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    >> "$LOG_FILE" 2>&1
log "    Dependencies installed (full output in $LOG_FILE)"

# ── Step 5: Laravel optimize ──────────────────────────────────
# CLAUDE.md rule: artisan cache commands MUST run as www-data so the cache
# files end up writable by php-fpm (which runs as www-data). Running as root
# creates root-owned cache files → next runtime cache write fails with 500.
# (Incident 2026-04-28: /admin/login 500 due to root-owned cache subdirs.)
log "==> Running Laravel cache/optimize (as $APP_USER)"
sudo -u "$APP_USER" php artisan optimize:clear
sudo -u "$APP_USER" php artisan config:cache
sudo -u "$APP_USER" php artisan route:cache
sudo -u "$APP_USER" php artisan view:cache

# ── Step 6: Pre-migration sanity check ────────────────────────
log "==> Sanity check: artisan boots"
sudo -u "$APP_USER" php artisan about >/dev/null
log "    Artisan OK"

# ── Step 7: Migrations ────────────────────────────────────────
log "==> Running migrations (if any)"
sudo -u "$APP_USER" php artisan migrate --force --no-interaction >> "$LOG_FILE" 2>&1
log "    Migrations done (full output in $LOG_FILE)"

# ── Step 8: Restart services ─────────────────────────────────
log "==> Restarting services"

# PHP-FPM
if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
    systemctl reload php8.3-fpm
    log "    PHP-FPM 8.3 reloaded"
elif systemctl is-active --quiet php8.2-fpm 2>/dev/null; then
    systemctl reload php8.2-fpm
    log "    PHP-FPM 8.2 reloaded"
fi

# PM2: only THIS app's processes (not all server processes)
if command -v pm2 &>/dev/null; then
    for proc in $APP_PM2_PROCESSES; do
        if pm2 describe "$proc" >/dev/null 2>&1; then
            pm2 restart "$proc" --update-env 2>&1 | tail -5
            log "    PM2: $proc restarted"
        else
            log "    PM2: $proc not found, skipping"
        fi
    done
fi

# Laravel queue workers
sudo -u "$APP_USER" php artisan queue:restart 2>/dev/null || true
log "    Queue workers signaled to restart"

# ── Step 9: Health checks ────────────────────────────────────
set +e
log "==> Running health checks"

CHECKS_PASSED=0
CHECKS_TOTAL=5

# Check 1: DB connection
if sudo -u "$APP_USER" php artisan tinker --execute="DB::select('select 1');" >/dev/null 2>&1; then
    log "    ✅ DB connection OK"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ DB connection FAILED"
fi

# Check 2: Commit matches
LIVE_SHA=$(git rev-parse HEAD)
if [ "$LIVE_SHA" = "$DEPLOYED_SHA" ]; then
    log "    ✅ Commit matches: ${LIVE_SHA:0:8}"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ Commit mismatch: expected ${DEPLOYED_SHA:0:8}, got ${LIVE_SHA:0:8}"
fi

# Check 3: App PM2 process(es) online
if command -v pm2 &>/dev/null; then
    PM2_OK=true
    for proc in $APP_PM2_PROCESSES; do
        if pm2 describe "$proc" 2>/dev/null | grep -q "status.*online"; then
            log "    ✅ PM2: $proc online"
        else
            log "    ❌ PM2: $proc NOT online"
            PM2_OK=false
        fi
    done
    if $PM2_OK; then
        CHECKS_PASSED=$((CHECKS_PASSED + 1))
    fi
else
    log "    ⏭ PM2 not installed, skipping"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
fi

# Check 4: Cache directory writable
if runuser -u "$APP_USER" -- test -w storage/framework/cache/data 2>/dev/null; then
    log "    ✅ Cache dir writable by $APP_USER"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ Cache dir NOT writable by $APP_USER"
fi

# Check 5: HTTP healthz endpoint
# Nginx routes by Host header; hitting 127.0.0.1 without one lands on the
# default server (404). Force the vhost so Laravel actually answers.
APP_HOST="jahongir-app.uz"
if curl -fsS -H "Host: $APP_HOST" "http://127.0.0.1/api/healthz" >/dev/null 2>&1; then
    log "    ✅ HTTP /healthz OK"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
elif curl -fsSk -H "Host: $APP_HOST" "https://127.0.0.1/api/healthz" >/dev/null 2>&1; then
    log "    ✅ HTTP /healthz OK (via HTTPS)"
    CHECKS_PASSED=$((CHECKS_PASSED + 1))
else
    log "    ❌ HTTP /healthz FAILED"
fi

set -e

# ── Summary ──────────────────────────────────────────────────
log "==> Deploy complete: $RELEASE_REF ($DEPLOYED_SHA)"
log "    Health: $CHECKS_PASSED/$CHECKS_TOTAL checks passed"
log "    Tag: $DEPLOYED_TAG"

# Record deploy in history (even on partial success)
echo "${DEPLOYED_AT} | ${DEPLOYED_TAG} | ${DEPLOYED_SHA:0:8} | ${OPERATOR} | ${CHECKS_PASSED}/${CHECKS_TOTAL}" >> "$DEPLOY_HISTORY"

if [ "$CHECKS_PASSED" -lt "$CHECKS_TOTAL" ]; then
    log "⚠️  DEPLOY COMPLETED WITH WARNINGS — check health failures above"
    exit 1
fi

log "✅ Deploy successful"
log ""
log "── Last 5 deploys ──────────────────────────────────────"
tail -5 "$DEPLOY_HISTORY" | while IFS= read -r line; do log "    $line"; done
log "────────────────────────────────────────────────────────"
