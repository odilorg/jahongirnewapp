#!/bin/bash
# Queue worker wrapper with pre-flight config validation.
#
# PM2 should point to this script instead of calling `php artisan queue:work` directly.
# If config validation fails, the script sleeps before exiting so PM2 does not
# rapid-restart loop (which would spam logs and CPU).
#
# Example PM2 ecosystem entry:
#   {
#     name: 'hotel-queue',
#     script: '/var/www/jahongirnewapp/scripts/queue-worker.sh',
#     interpreter: 'bash',
#     autorestart: true,
#   }

set -e

cd /var/www/jahongirnewapp

# Pre-flight: validate all required production config values
php artisan app:assert-production-config
STATUS=$?

if [ $STATUS -ne 0 ]; then
    echo "FATAL: Production config validation failed. Queue worker refusing to start." >&2
    echo "Fix the config issues above and then restart the process." >&2
    # Sleep before exit so PM2 does not spin in a tight restart loop.
    sleep 30
    exit 1
fi

# Config is valid — hand off to the real worker.
# exec replaces this process so PM2 monitors the artisan process directly.
exec php artisan queue:work --sleep=3 --tries=3 --timeout=90
