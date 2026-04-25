#!/usr/bin/env bash
# Reset jahongirnewapp_test to a clean migrated state.
# Safe to run any time — never touches the production jahongir DB.
set -euo pipefail

APP_DIR=/var/www/jahongirnewapp
DB_USER=root
DB_PASS=$(grep '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2-)

echo '1/4  Recreating jahongirnewapp_test via MySQL ...'
mysql -u "$DB_USER" -p"$DB_PASS" 2>/dev/null <<SQL
DROP DATABASE IF EXISTS jahongirnewapp_test;
CREATE DATABASE jahongirnewapp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL

echo '2/4  Clearing config cache ...'
cd "$APP_DIR" && php artisan config:clear

echo '3/4  Running migrations against jahongirnewapp_test ...'
cd "$APP_DIR" && php artisan migrate --env=testing --force 2>&1 | tail -5

echo '4/4  Restoring production config cache ...'
cd "$APP_DIR" && APP_ENV=production php artisan config:cache > /dev/null

TABLES=$(mysql -u "$DB_USER" -p"$DB_PASS" jahongirnewapp_test   -se 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="jahongirnewapp_test";' 2>/dev/null)
echo "Done — $TABLES tables in jahongirnewapp_test"
