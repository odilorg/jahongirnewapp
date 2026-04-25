#!/bin/bash
# Monitor: jahongir-travel.uz /api/v1/inquiries endpoint + Redis health
# Runs every 30 minutes. Alerts via Telegram if endpoint or Redis is down.

ENDPOINT="https://jahongir-travel.uz/api/v1/inquiries"
BOT_TOKEN="TELEGRAM_BOT_TOKEN_REDACTED"
CHAT_ID="38738713"
API_LOCK="/tmp/booking-api-alert.lock"
REDIS_LOCK="/tmp/redis-alert.lock"

send_alert() {
  local message="$1"
  curl -s "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
    -d "chat_id=${CHAT_ID}&text=${message}&parse_mode=Markdown" > /dev/null
}

# ── Redis health check ──────────────────────────────────────────────────────
REDIS_OK=$(redis-cli ping 2>/dev/null)

if [ "$REDIS_OK" != "PONG" ]; then
  if [ ! -f "$REDIS_LOCK" ]; then
    touch "$REDIS_LOCK"
    send_alert "🔴 Redis is DOWN%0A%0ACache and session driver will fail. Laravel 500s likely.%0ACheck: systemctl status redis-server"
    echo "$(date): Redis DOWN" >> /var/log/booking-api-monitor.log
  fi
else
  if [ -f "$REDIS_LOCK" ]; then
    rm -f "$REDIS_LOCK"
    send_alert "✅ Redis RECOVERED%0A%0Aredis-cli ping returned PONG."
  fi
fi

# ── Booking API check ───────────────────────────────────────────────────────
HTTP_CODE=$(curl -sk -o /dev/null -w '%{http_code}' \
  -X POST "$ENDPOINT" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"tour_name_snapshot":"Monitor","customer_name":"Monitor","customer_email":"mon@mon.com","customer_phone":"+99800000000","people_adults":1,"source":"monitor"}' \
  --max-time 10)

# 201 = saved, 422 = validation error = Laravel responded = endpoint alive
if [ "$HTTP_CODE" = "201" ] || [ "$HTTP_CODE" = "422" ]; then
  if [ -f "$API_LOCK" ]; then
    rm -f "$API_LOCK"
    send_alert "✅ Booking API RECOVERED%0A%0APOST /api/v1/inquiries is responding again (HTTP ${HTTP_CODE})."
  fi
  exit 0
fi

# Endpoint is down — alert once (lock prevents spam every 30 min)
if [ -f "$API_LOCK" ]; then
  exit 0
fi

touch "$API_LOCK"
send_alert "🚨 Booking API DOWN%0A%0AEndpoint: POST /api/v1/inquiries%0AHTTP code: ${HTTP_CODE:-timeout}%0A%0AWebsite form submissions are NOT being saved to DB.%0ACheck: nginx config + jahongirnewapp."
echo "$(date): booking API check failed, HTTP ${HTTP_CODE}" >> /var/log/booking-api-monitor.log
