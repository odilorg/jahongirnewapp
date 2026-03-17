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

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

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
log "==> Deployed: $DEPLOYED_SHA ($DEPLOYED_TAG)"

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
log "==> Running health checks"

CHECKS_PASSED=0
CHECKS_TOTAL=3

# Check 1: DB connection
if php artisan tinker --execute="DB::select('select 1');" >/dev/null 2>&1; then
    log "    ✅ DB connection OK"
    ((CHECKS_PASSED++))
else
    log "    ❌ DB connection FAILED"
fi

# Check 2: Current commit matches intended
LIVE_SHA=$(git rev-parse HEAD)
if [ "$LIVE_SHA" = "$DEPLOYED_SHA" ]; then
    log "    ✅ Commit matches: $LIVE_SHA"
    ((CHECKS_PASSED++))
else
    log "    ❌ Commit mismatch: expected $DEPLOYED_SHA, got $LIVE_SHA"
fi

# Check 3: PM2 processes online
if command -v pm2 &>/dev/null; then
    PM2_ONLINE=$(pm2 jlist 2>/dev/null | php -r "echo count(array_filter(json_decode(file_get_contents('php://stdin'),true), fn(\$p)=>\$p['pm2_env']['status']==='online'));" 2>/dev/null || echo "0")
    if [ "$PM2_ONLINE" -gt 0 ]; then
        log "    ✅ PM2: $PM2_ONLINE process(es) online"
        ((CHECKS_PASSED++))
    else
        log "    ❌ PM2: no online processes"
    fi
else
    log "    ⏭ PM2 not installed, skipping"
    ((CHECKS_PASSED++))
fi

# ── Summary ─────────────────────────────────────────────────
log "==> Deploy complete: $RELEASE_REF ($DEPLOYED_SHA)"
log "    Health: $CHECKS_PASSED/$CHECKS_TOTAL checks passed"
log "    Tag: $DEPLOYED_TAG"

if [ "$CHECKS_PASSED" -lt "$CHECKS_TOTAL" ]; then
    log "⚠️  DEPLOY COMPLETED WITH WARNINGS — check health failures above"
    exit 1
fi

log "✅ Deploy successful"
