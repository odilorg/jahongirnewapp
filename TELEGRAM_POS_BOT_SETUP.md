# Telegram POS Bot Setup Guide

## Overview

The Telegram POS Bot allows cashiers to manage their shifts and record transactions directly through Telegram. This guide covers setup, configuration, and usage.

---

## Phase 1: Authentication & User Management ✅

### Features Implemented
- ✅ Phone number authentication
- ✅ Session management (15-minute timeout)
- ✅ Multi-language support (English, Russian, Uzbek)
- ✅ Activity logging
- ✅ Integration with existing User model

---

## Bot Setup

### 1. Create Telegram Bot

1. Open Telegram and search for `@BotFather`
2. Send `/newbot` command
3. Follow the prompts to create your bot
4. Copy the bot token provided

**Your Bot Token:** `8443847020:AAEv_a3g9Ak5kZbeGfE0Dv59XVPddyix08M`

### 2. Environment Configuration

Add the following to your `.env` file:

```env
# Telegram POS Bot Configuration
TELEGRAM_POS_BOT_TOKEN=8443847020:AAEv_a3g9Ak5kZbeGfE0Dv59XVPddyix08M
TELEGRAM_POS_WEBHOOK_URL=https://yourdomain.com/api/telegram/pos/webhook
TELEGRAM_POS_SESSION_TIMEOUT=15
```

**Note:** Replace `yourdomain.com` with your actual domain.

### 3. Run Migrations

```bash
php artisan migrate
```

This creates:
- `telegram_pos_sessions` - User sessions
- `telegram_pos_activities` - Activity audit log

### 4. Set Webhook

Set the webhook URL for your bot:

```bash
php artisan telegram:pos:set-webhook
```

Or with a custom URL:

```bash
php artisan telegram:pos:set-webhook --url=https://yourdomain.com/api/telegram/pos/webhook
```

Verify webhook status:

```bash
curl https://api.telegram.org/bot8443847020:AAEv_a3g9Ak5kZbeGfE0Dv59XVPddyix08M/getWebhookInfo
```

---

## User Authorization

### Prerequisites

Users must have:
1. ✅ Phone number registered in the `users` table
2. ✅ One of these roles: `cashier`, `manager`, or `super_admin`
3. ✅ Assigned to at least one location (for shift management)

### Adding Users

**Via Filament Admin Panel:**

1. Navigate to Users management
2. Create/Edit user
3. Add phone number (with country code, e.g., `+998901234567`)
4. Assign role: `cashier` or `manager`
5. Assign to location(s)

**Via Database:**

```sql
-- Update existing user with phone number
UPDATE users 
SET phone_number = '998901234567' 
WHERE email = 'cashier@example.com';

-- Assign role (if not already assigned)
-- This is handled by Spatie Permission package
```

---

## How It Works

### Authentication Flow

1. **User starts bot:** `/start` command
2. **Bot requests phone:** Shows "Share Phone Number" button
3. **User shares contact:** Telegram sends phone number
4. **Bot validates:**
   - Checks if phone exists in `users` table
   - Verifies user has required role
5. **Session created:** 15-minute session with auto-refresh on activity
6. **Main menu shown:** User can now access POS features

### Session Management

- **Session timeout:** 15 minutes of inactivity
- **Auto-refresh:** Each interaction extends the session
- **Multiple devices:** One session per user (last device wins)
- **Cleanup:** Run `php artisan telegram:pos:clear-sessions` to remove expired sessions

---

## Supported Languages

The bot automatically detects user's Telegram language and supports:

- 🇬🇧 **English** (en)
- 🇷🇺 **Russian** (ru)
- 🇺🇿 **Uzbek** (uz)

Users can change language anytime via Settings menu.

---

## Commands

### Artisan Commands

```bash
# Set webhook
php artisan telegram:pos:set-webhook

# Clear expired sessions (should be scheduled hourly)
php artisan telegram:pos:clear-sessions
```

### Bot Commands

- `/start` - Start the bot and authenticate
- `/language` - Change language
- `/help` - Show help text

### Main Menu Buttons

- 🟢 **Start Shift** - Begin cashier shift *(Coming in Phase 2)*
- 📊 **My Shift** - View current shift status *(Coming in Phase 2)*
- 💰 **Record Transaction** - Record cash transaction *(Coming in Phase 3)*
- 🔴 **Close Shift** - End shift and count cash *(Coming in Phase 2)*
- ℹ️ **Help** - Show help information
- ⚙️ **Settings** - Change language and preferences

---

## Scheduled Tasks

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Clear expired POS sessions every hour
    $schedule->command('telegram:pos:clear-sessions')->hourly();
}
```

---

## Security

### Authentication
- ✅ Phone number verification
- ✅ Role-based access control
- ✅ Session timeout (15 minutes)
- ✅ Activity logging for audit trail

### Best Practices
1. Use HTTPS for webhook URL
2. Keep bot token secret
3. Regularly review activity logs
4. Set appropriate user roles
5. Monitor for suspicious activity

---

## Troubleshooting

### Bot not responding

1. Check webhook is set correctly:
   ```bash
   curl https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo
   ```

2. Check logs:
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. Verify bot token in `.env`:
   ```env
   TELEGRAM_POS_BOT_TOKEN=8443847020:AAEv_a3g9Ak5kZbeGfE0Dv59XVPddyix08M
   ```

### Authentication fails

1. Verify user has phone number in database
2. Check user has required role (`cashier`, `manager`, or `super_admin`)
3. Check `telegram_pos_activities` table for error logs

### Session expires too quickly

1. Increase timeout in `.env`:
   ```env
   TELEGRAM_POS_SESSION_TIMEOUT=30
   ```

2. Clear config cache:
   ```bash
   php artisan config:clear
   ```

---

## Database Tables

### telegram_pos_sessions

Stores active user sessions:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| telegram_user_id | bigint | Telegram user ID |
| chat_id | bigint | Telegram chat ID |
| user_id | bigint | App user ID (FK) |
| state | string | Session state |
| data | json | Session context |
| language | string | User language (en/ru/uz) |
| last_activity_at | timestamp | Last activity |
| expires_at | timestamp | Expiry time |

### telegram_pos_activities

Audit log of all activities:

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | App user ID (FK) |
| telegram_user_id | bigint | Telegram user ID |
| action | string | Action type |
| details | text | Action details |
| ip_address | string | Request IP |
| created_at | timestamp | When it happened |

---

## API Endpoints

### Webhook (POST)
```
POST /api/telegram/pos/webhook
```
Receives updates from Telegram.

### Set Webhook (POST)
```
POST /api/telegram/pos/set-webhook
Authorization: Bearer <token>
```
Programmatically set webhook URL.

### Get Webhook Info (GET)
```
GET /api/telegram/pos/webhook-info
Authorization: Bearer <token>
```
Get current webhook information.

---

## Next Steps (Upcoming Phases)

### Phase 2: Shift Management
- Start shift with auto-location detection
- View current shift status and balances
- Close shift with multi-currency counting

### Phase 3: Transaction Recording
- Cash IN/OUT transactions
- Complex (exchange) transactions
- Multi-currency support

### Phase 4: Reporting & Notifications
- Shift summary reports
- Manager notifications
- Analytics dashboard

### Phase 5: Advanced Features
- Voice commands
- Inline mode
- Offline support

---

## Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Review activity table: `telegram_pos_activities`
3. Contact system administrator

---

## Development

### Testing the Bot

1. Start a conversation with your bot on Telegram
2. Send `/start` command
3. Share your phone number when prompted
4. Verify authentication succeeds
5. Test language switching
6. Check session management

### Adding New Features

See `app/Http/Controllers/TelegramPosController.php` for main bot logic.

---

**Version:** 1.0 (Phase 1)  
**Last Updated:** October 14, 2025

