#!/bin/bash
# Local deploy: merge branch to main → push → pull on VPS → restart PHP
#
# Usage:
#   ./deploy-local.sh              Deploy current main to VPS (after merge)
#   ./deploy-local.sh --merge      Squash-merge current branch into main, then deploy
#   ./deploy-local.sh --push-only  Just push current branch (for review before merge)

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

BRANCH=$(git branch --show-current)

case "${1:-}" in
    --push-only)
        # Push current feature branch for review
        echo -e "${YELLOW}🚀 Pushing branch '${BRANCH}'...${NC}"
        git push -u origin "$BRANCH"
        echo -e "${GREEN}✅ Branch pushed. Create PR or ask for review.${NC}"
        exit 0
        ;;
    --merge)
        # Squash-merge current branch into main, then deploy
        if [ "$BRANCH" = "main" ]; then
            echo -e "${RED}❌ Already on main. Switch to feature branch first.${NC}"
            exit 1
        fi
        echo -e "${YELLOW}🔀 Squash-merging '${BRANCH}' into main...${NC}"
        git checkout main
        git pull origin main
        git merge --squash "$BRANCH"
        git commit -m "$(git log main.."$BRANCH" --format=%s | head -1)

Squashed from branch: ${BRANCH}

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
        git push origin main
        echo -e "${YELLOW}🗑️  Cleaning up branch...${NC}"
        git branch -D "$BRANCH"
        git push origin --delete "$BRANCH" 2>/dev/null || true
        BRANCH="main"
        ;;
    "")
        # Deploy current main
        if [ "$BRANCH" != "main" ]; then
            echo -e "${RED}❌ Not on main. Use --merge to merge first, or --push-only to push branch.${NC}"
            exit 1
        fi
        ;;
    *)
        echo "Usage: ./deploy-local.sh [--merge|--push-only]"
        exit 1
        ;;
esac

# Deploy to VPS
echo -e "${YELLOW}📡 Deploying main to VPS...${NC}"
ssh main-vps bash -c "'
set -e
cd /var/www/jahongirnewapp

# Pull code
git fetch origin && git reset --hard origin/main

# Validate config before caching (abort if anything is wrong)
php artisan app:assert-production-config
if [ \$? -ne 0 ]; then
    echo \"DEPLOY ABORTED: Config validation failed\"
    exit 1
fi

# Run migrations, then cache
php artisan migrate --force 2>/dev/null
php artisan config:cache
php artisan route:cache

# Restart services
systemctl restart php8.3-fpm
pm2 restart hotel-queue 2>/dev/null
php artisan queue:restart 2>/dev/null

# Post-deploy smoke test
php artisan tinker --execute=\"DB::connection()->getPdo(); echo \\\"DB: OK\\\";\" 2>/dev/null
PENDING=\$(php artisan tinker --execute=\"echo DB::table(\\\"jobs\\\")->count();\" 2>/dev/null)
echo \"Queue pending: \$PENDING\"
echo DEPLOYED
'"

echo -e "${GREEN}✅ Deployed to VPS!${NC}"
