# Telegram Booking Bot - Testing Guide

## Setup Status

- Webhook: https://jahongir-app.uz/api/booking/bot/webhook
- Room Data: 15 rooms from Jahongir Hotel
- Bot Token: 5019025912:AAH...

## Quick Test

### 1. Authorize Your Phone

```bash
cd /var/www/jahongirnewapp
php artisan tinker --execute="App\Models\AuthorizedStaff::firstOrCreate(['phone_number' => '+998YOURPHONE'], ['full_name' => 'Your Name', 'role' => 'staff', 'is_active' => true]);"
```

### 2. Test Commands

Send to bot:
- `check avail dec 15-17`
- `book room 12 under John Carpenter dec 20-22 tel +1234567890 email john@example.com`

### 3. Check Logs

```bash
tail -f storage/logs/laravel.log
```

## Available Rooms
1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15
