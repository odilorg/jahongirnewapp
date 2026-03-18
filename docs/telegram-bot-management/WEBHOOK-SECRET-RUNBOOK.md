# Webhook Secret Enforcement — Cutover Runbook

## Overview

The `VerifyTelegramWebhook` middleware now protects all 9 Telegram webhook
endpoints. It operates in **two modes per bot**:

- **UNENFORCED** (env var empty): requests pass through, warning logged once per process
- **ENFORCED** (env var set): `X-Telegram-Bot-Api-Secret-Token` header must match via `hash_equals()`

Bots are migrated one at a time. No traffic disruption until an env var is set.

---

## Prerequisites

- SSH access to VPS
- Access to `.env` file on VPS
- Each bot's API token (already in `.env`)

---

## Bot-by-bot cutover order

Recommended order: lowest traffic / lowest risk first.

| # | Bot | Env var | Webhook route |
|---|-----|---------|---------------|
| 1 | kitchen | `KITCHEN_WEBHOOK_SECRET` | `/api/telegram/kitchen/webhook` |
| 2 | housekeeping | `HOUSEKEEPING_WEBHOOK_SECRET` | `/api/telegram/housekeeping/webhook` |
| 3 | owner-alert | `OWNER_ALERT_WEBHOOK_SECRET` | `/api/telegram/owner/webhook` |
| 4 | driver-guide | `DRIVER_GUIDE_WEBHOOK_SECRET` | `/api/telegram/driver_guide_signup` |
| 5 | cashier | `CASHIER_BOT_WEBHOOK_SECRET` | `/api/telegram/cashier/webhook` |
| 6 | pos | `TELEGRAM_POS_SECRET_TOKEN` | `/api/telegram/pos/webhook` |
| 7 | booking | `TELEGRAM_BOOKING_SECRET_TOKEN` | `/api/telegram/bot/webhook` + `/api/booking/bot/webhook` |
| 8 | main | `TELEGRAM_MAIN_WEBHOOK_SECRET` | `/api/telegram/webhook` |

---

## Step 0: Deploy the code

Tag, push, and deploy using the production deploy script:

```bash
# On local machine
git tag release-YYYY-MM-DD-webhook-secrets
git push --tags

# On VPS
ssh -i /home/odil/projects/id_rsa -p 2222 root@62.72.22.205
/var/www/jahongirnewapp/scripts/deploy-production.sh release-YYYY-MM-DD-webhook-secrets
```

After deploy, all 9 bots continue working in UNENFORCED mode.
Verify with:

```bash
pm2 logs --nostream --lines 30 | grep "UNENFORCED"
# Should see one warning per bot that received a webhook since deploy
```

---

## Step 1: Generate a secret (per bot)

```bash
SECRET=$(openssl rand -hex 32)
echo "Generated secret: $SECRET"
# Save this — you need it for both .env and Telegram registration
```

---

## Step 2: Set the env var

Use `sed` to safely replace an existing key or append if missing:

```bash
ENV_FILE="/var/www/jahongirnewapp/.env"
KEY="KITCHEN_WEBHOOK_SECRET"
VALUE="$SECRET"

if grep -q "^${KEY}=" "$ENV_FILE"; then
    # Key exists — replace in place
    sed -i "s|^${KEY}=.*|${KEY}=${VALUE}|" "$ENV_FILE"
else
    # Key missing — append
    echo "${KEY}=${VALUE}" >> "$ENV_FILE"
fi

# Verify the value was written correctly
grep "^${KEY}=" "$ENV_FILE"
```

Clear Laravel's config cache so the new value takes effect:

```bash
cd /var/www/jahongirnewapp
php artisan config:cache
```

---

## Step 3: Retrieve the bot token for webhook registration

Use `php artisan tinker` to read the token from Laravel's config
(avoids fragile grep/cut parsing of `.env`):

```bash
cd /var/www/jahongirnewapp

# For kitchen bot:
php artisan tinker --execute="echo config('services.kitchen_bot.token');"

# For other bots:
# php artisan tinker --execute="echo config('services.cashier_bot.token');"
# php artisan tinker --execute="echo config('services.telegram_pos_bot.token');"
# php artisan tinker --execute="echo config('services.owner_alert_bot.token');"
# php artisan tinker --execute="echo config('services.driver_guide_bot.token');"
# php artisan tinker --execute="echo config('services.housekeeping_bot.token');"
# php artisan tinker --execute="echo config('services.telegram_booking_bot.token');"
# php artisan tinker --execute="echo config('services.telegram.bot_token');"
```

> **Important:** After reading the token, do NOT leave it in shell history.
> Run `history -d $(history 1 | awk '{print $1}')` to remove the last entry.

---

## Step 4: Re-register the webhook with Telegram

```bash
TOKEN="<paste token from Step 3>"
SECRET="<paste secret from Step 1>"
URL="https://jahongir-app.uz/api/telegram/kitchen/webhook"

curl -s "https://api.telegram.org/bot${TOKEN}/setWebhook" \
  -d "url=${URL}" \
  -d "secret_token=${SECRET}" | python3 -m json.tool
```

Expected response:

```json
{
    "ok": true,
    "result": true,
    "description": "Webhook was set"
}
```

**Webhook URLs per bot:**

| Bot | URL |
|-----|-----|
| kitchen | `https://jahongir-app.uz/api/telegram/kitchen/webhook` |
| housekeeping | `https://jahongir-app.uz/api/telegram/housekeeping/webhook` |
| owner-alert | `https://jahongir-app.uz/api/telegram/owner/webhook` |
| driver-guide | `https://jahongir-app.uz/api/telegram/driver_guide_signup` |
| cashier | `https://jahongir-app.uz/api/telegram/cashier/webhook` |
| pos | `https://jahongir-app.uz/api/telegram/pos/webhook` |
| booking (avail) | `https://jahongir-app.uz/api/telegram/bot/webhook` |
| booking (main) | `https://jahongir-app.uz/api/booking/bot/webhook` |
| main | `https://jahongir-app.uz/api/telegram/webhook` |

> **Note:** The `booking` slug covers two routes. Both share the same
> `TELEGRAM_BOOKING_SECRET_TOKEN` env var. Only one `setWebhook` call
> is needed per bot (Telegram has one webhook URL per bot token).

---

## Step 5: Verify the webhook is working

### 5a. Check webhook info from Telegram

```bash
curl -s "https://api.telegram.org/bot${TOKEN}/getWebhookInfo" | python3 -m json.tool
```

Verify:
- `url` matches the expected URL
- `has_custom_certificate` is `false`
- `pending_update_count` is `0` (or low)
- `last_error_date` is absent or old

### 5b. Functional test: send a message to the bot

Open Telegram, send a message to the bot (e.g., type `/start`).
The bot should respond normally.

Check PM2 logs for the bot processing the update:

```bash
pm2 logs --nostream --lines 20 | grep -i "kitchen"
# Should see normal request processing, NO "UNENFORCED" warnings
# Should see NO "invalid secret" warnings
```

### 5c. Confirm forged requests are rejected

Send a request WITHOUT the secret header — this tests that the
middleware is rejecting unsigned traffic:

```bash
curl -s -o /dev/null -w "%{http_code}" -X POST \
  -H "Content-Type: application/json" \
  -d '{"update_id":1,"message":{"text":"forged"}}' \
  "https://jahongir-app.uz/api/telegram/kitchen/webhook"
```

Expected: `403`

> **Note:** A 403 here confirms the middleware rejects the unsigned request.
> It does NOT confirm the webhook handler processes real updates correctly.
> Step 5b (sending a real Telegram message) is the functional health check.

---

## Step 6: Repeat for next bot

Go back to Step 1 and repeat for the next bot in the cutover order.
Wait a few minutes between bots to observe logs for any issues.

---

## Rollback — per bot (instant, no deploy)

If a bot stops receiving webhooks after cutover:

```bash
ENV_FILE="/var/www/jahongirnewapp/.env"
KEY="KITCHEN_WEBHOOK_SECRET"

# Remove the env var (sets it to empty)
sed -i "s|^${KEY}=.*|${KEY}=|" "$ENV_FILE"

# Clear config cache
cd /var/www/jahongirnewapp
php artisan config:cache

# Bot immediately returns to UNENFORCED mode (pass-through)
# No webhook re-registration needed — Telegram still sends the header,
# but middleware ignores it when the expected secret is empty
```

---

## Rollback — full code revert

If the middleware itself is causing issues:

```bash
# On local machine: identify the commit
git log --oneline -5

# Revert and tag
git revert <webhook-rollout-commit>
git tag release-YYYY-MM-DD-revert-webhook
git push --tags

# On VPS
/var/www/jahongirnewapp/scripts/deploy-production.sh release-YYYY-MM-DD-revert-webhook
```

---

## Monitoring migration progress

```bash
# Which bots are still unenforced? (logged once per process per bot)
pm2 logs --nostream --lines 500 | grep "UNENFORCED"

# Any rejected forged requests?
pm2 logs --nostream --lines 500 | grep "invalid secret"

# Overall webhook health
for BOT in kitchen housekeeping owner-alert driver-guide cashier pos booking main; do
    echo -n "$BOT: "
    pm2 logs --nostream --lines 200 2>/dev/null | grep -c "UNENFORCED.*$BOT" || echo "0"
done
```

Once all 8 bots show zero UNENFORCED warnings after a PM2 restart,
migration is complete.

---

## Post-migration cleanup

After all bots are enforced and verified (recommend waiting 1 week):

1. The UNENFORCED code path in the middleware can optionally be changed
   from pass-through to fail-closed (reject when secret is empty).
   This is a separate code change — not part of this rollout.

2. The `ValidateTelegramRequest` middleware (replaced by this middleware
   for POS bot) can be deleted.

3. UNENFORCED log warnings will naturally stop appearing once all env
   vars are set, since the warning fires at most once per process and
   only for bots with empty secrets.
