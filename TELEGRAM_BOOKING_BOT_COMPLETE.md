# Telegram Booking Bot - Complete Documentation

**Project:** Internal Hotel Booking Bot for Jahongir Hotels
**Last Updated:** 2025-10-12
**Status:** Phase 1 Complete - Availability Checking Implemented
**Server:** 62.72.22.205:2222
**Path:** /var/www/jahongirnewapp
**Branch:** feature/telegram-bot

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Current Implementation Status](#current-implementation-status)
3. [Architecture](#architecture)
4. [Database Schema](#database-schema)
5. [Room Mappings](#room-mappings)
6. [API Integrations](#api-integrations)
7. [Bot Usage](#bot-usage)
8. [Setup & Configuration](#setup--configuration)
9. [Code Structure](#code-structure)
10. [Troubleshooting](#troubleshooting)
11. [Next Phase - TODO](#next-phase---todo)

---

## Project Overview

### Purpose
Internal Telegram bot for hotel booking staff to manage room reservations across two properties using natural language commands.

### Properties Managed
- **Jahongir Hotel** (Beds24 Property ID: `41097`) - 15 room units
- **Jahongir Premium Hotel** (Beds24 Property ID: `172793`) - 19 room units
- **Total:** 34 room units

### Key Users
- **Authorized Phone:** +998 91 555 08 08
- **Target Users:** Hotel booking personnel (internal staff only)

### Technology Stack
- **Framework:** Laravel 10.x (PHP 8.2)
- **Database:** MySQL
- **Queue:** Sync (can upgrade to Redis)
- **APIs:** Telegram Bot API, Beds24 API V2, OpenAI GPT-3.5

---

## Current Implementation Status

### ✅ Phase 1: Completed Features

1. **Phone Number Authorization**
   - Staff authentication via Telegram phone sharing
   - Database tracking in `authorized_staff` table
   - Authorization service implemented

2. **Natural Language Processing**
   - OpenAI GPT-3.5 Turbo integration
   - Parses commands like "check avail jan 2-3"
   - Intent classification system

3. **Real-Time Availability Checking**
   - Queries Beds24 API for existing bookings
   - Filters booked rooms from available inventory
   - Shows room details (unit name, type, price)
   - Handles 34 rooms across both hotels

4. **Room Unit Mapping System**
   - Staff reference rooms by friendly names ("room 12")
   - Maps to Beds24 room IDs automatically
   - Supports duplicate unit names across properties

5. **Audit Trail**
   - All commands logged to `staff_booking_requests`
   - Tracks intent, parsed data, responses

### 🚧 Phase 2: Partially Implemented

1. **Booking Creation** - Service exists, needs integration
2. **Booking Modification** - Service exists, needs handlers
3. **Booking Cancellation** - Service exists, needs handlers
4. **View Bookings** - Placeholder only

---

## Architecture

### System Flow

```
┌─────────────┐
│ Telegram    │
│ User        │
└─────┬───────┘
      │ Message
      ↓
┌─────────────────────┐
│ Telegram Bot API    │
│ (Webhook)           │
└─────┬───────────────┘
      │ POST /api/booking/bot/webhook
      ↓
┌─────────────────────────────┐
│ BookingWebhookController    │
└─────┬───────────────────────┘
      │ Dispatch Job
      ↓
┌─────────────────────────────┐
│ ProcessBookingMessage (Job) │
│ ┌─────────────────────────┐ │
│ │ 1. Check Authorization  │ │
│ │ 2. Parse Intent (OpenAI)│ │
│ │ 3. Route by Intent      │ │
│ │ 4. Execute Action       │ │
│ │ 5. Log Request          │ │
│ └─────────────────────────┘ │
└─────┬───────────────────────┘
      │
      ├─→ [Beds24BookingService] ──→ Beds24 API V2
      ├─→ [TelegramBotService] ───→ Telegram API
      └─→ [BookingIntentParser] ──→ OpenAI API
```

### Service Layer

```
app/Services/
├── TelegramBotService.php          # Telegram API wrapper
├── Beds24BookingService.php        # Beds24 API V2 client
├── BookingIntentParser.php         # OpenAI NLP parser
└── StaffAuthorizationService.php   # Phone-based auth
```

---

## Database Schema

### 1. `authorized_staff`
Stores authorized staff members.

```sql
CREATE TABLE authorized_staff (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    telegram_user_id VARCHAR(100) UNIQUE,
    phone_number VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    username VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    authorized_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_phone (phone_number),
    INDEX idx_telegram_user (telegram_user_id)
);
```

**Current Data:** 1 authorized user (+998 91 555 08 08)

### 2. `room_unit_mappings`
Maps friendly unit names to Beds24 room IDs.

```sql
CREATE TABLE room_unit_mappings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    unit_name VARCHAR(50),
    property_id VARCHAR(100),
    property_name VARCHAR(255),
    room_id VARCHAR(100),
    room_name VARCHAR(255),
    room_type VARCHAR(100),
    max_guests INT,
    base_price DECIMAL(10,2),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE KEY unit_property_room_unique (unit_name, property_id, room_id),
    INDEX idx_unit_name (unit_name),
    INDEX idx_property_id (property_id),
    INDEX idx_room_id (room_id)
);
```

**Important Notes:**
- Unique constraint: `(unit_name, property_id, room_id)`
- Allows same unit names across different properties
- Allows same unit names for different room types
- **Current Data:** 34 rooms (see [Room Mappings](#room-mappings))

### 3. `staff_booking_requests`
Audit log for all bot interactions.

```sql
CREATE TABLE staff_booking_requests (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    authorized_staff_id BIGINT UNSIGNED,
    telegram_message_id VARCHAR(100),
    raw_message TEXT,
    parsed_intent VARCHAR(50),
    parsed_data JSON,
    response_sent TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    processed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (authorized_staff_id) REFERENCES authorized_staff(id),
    INDEX idx_staff (authorized_staff_id),
    INDEX idx_status (status)
);
```

**Status Values:** `pending`, `completed`, `failed`, `unauthorized`

### 4. `bot_managed_bookings`
Tracks bookings created via bot.

```sql
CREATE TABLE bot_managed_bookings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    staff_booking_request_id BIGINT UNSIGNED,
    beds24_booking_id VARCHAR(100) UNIQUE,
    property_id VARCHAR(100),
    room_id VARCHAR(100),
    guest_name VARCHAR(255),
    check_in DATE,
    check_out DATE,
    total_price DECIMAL(10,2),
    booking_status VARCHAR(50) DEFAULT 'confirmed',
    beds24_response JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (staff_booking_request_id) REFERENCES staff_booking_requests(id),
    INDEX idx_beds24_booking (beds24_booking_id),
    INDEX idx_dates (check_in, check_out)
);
```

---

## Room Mappings

### Jahongir Hotel (Property ID: 41097)
**Total:** 15 room units

| Unit | Room Type | Room Name | Beds24 Room ID |
|------|-----------|-----------|----------------|
| 1 | Single | 1 xona | 152726 |
| 2 | Twin | Twin Room | 94986 |
| 3 | Double | Large Double Room | 94991 |
| 4 | Single | Single Room | 94984 |
| 5 | Double | Large Double Room | 94991 |
| 6 | Twin | Twin Room | 94986 |
| 7 | Twin | Twin Room | 94986 |
| 8 | Single | Single Room | 94984 |
| 9 | Single | Single Room | 94984 |
| 10 | Double | Twin/Double | 144341 |
| 11 | Double | Double Room | 94982 |
| 12 | Suite | Junior Suite | 144342 |
| 13 | Double | Twin/Double | 144341 |
| 14 | Double | Double Room | 94982 |
| 15 | Family | Family Room | 97215 |

### Jahongir Premium Hotel (Property ID: 172793)
**Total:** 19 room units

| Unit | Room Type | Room Name | Beds24 Room ID |
|------|-----------|-----------|----------------|
| 10 | Triple | Deluxe triple | 377304 |
| 11 | Double | Standard Double | 377300 |
| 12 | Double | Double or Twin | 377291 |
| 14 | Double | Standard Queen | 377299 |
| 15 | Double | Deluxe Double/twin | 377301 |
| 16 | Double | Superior Double | 377302 |
| 17 | Double | Superior double/twin | 377303 |
| 18 | Double | Superior double/twin | 377303 |
| 19 | Double | Superior Double | 377302 |
| 20 | Triple | Deluxe triple | 377304 |
| 21 | Double | Standard Double | 377300 |
| 22 | Double | Double or Twin | 377291 |
| 23 | Single | Deluxe Single | 377298 |
| 24 | Double | Standard Queen | 377299 |
| 25 | Double | Deluxe Double/twin | 377301 |
| 26 | Double | Superior Double | 377302 |
| 27 | Double | Superior double/twin | 377303 |
| 28 | Double | Superior double/twin | 377303 |
| 29 | Double | Superior Double | 377302 |

**Note:** Some unit names overlap between properties but are distinguished by property_id.

---

## API Integrations

### Beds24 API V2

**Base URL:** `https://api.beds24.com/v2`
**Auth:** Token-based (`.env`: `BEDS24_API_V2_TOKEN`)

#### Endpoints Used

**1. GET /bookings** - Retrieve existing bookings
```php
// Used for availability checking
GET /bookings?arrival=2025-01-02&departure=2025-01-03&roomId=94982,94986
```

**Response:**
```json
{
  "success": true,
  "count": 2,
  "data": [
    {
      "id": "123456",
      "roomId": "94982",
      "arrival": "2025-01-02",
      "departure": "2025-01-03",
      ...
    }
  ]
}
```

**2. POST /bookings** - Create booking (method exists, not yet integrated)

**3. PUT /bookings/{id}** - Modify booking (method exists, not yet integrated)

**4. DELETE /bookings/{id}** - Cancel booking (method exists, not yet integrated)

#### Availability Strategy

The bot uses a **"booked room exclusion"** approach:

1. Query `/bookings` for requested date range
2. Extract which room IDs are already booked
3. Filter out booked rooms from configured room list
4. Return only available (unbooked) rooms

**Why not `/inventory/rooms/offers`?**
Initially attempted, but returns `"Occupancy not defined"` error. Beds24 requires occupancy-based pricing configuration which isn't set up.

### Telegram Bot API

**Webhook URL:** `https://jahongir-app.uz/api/booking/bot/webhook`

#### TelegramBotService Methods

```php
sendMessage(int $chatId, string $text, array $options = []): array
setWebhook(string $url): array
getWebhookInfo(): array
deleteWebhook(): array
```

**Important:** Markdown parsing disabled to prevent entity parsing errors:
```php
// Line 24 in sendMessage()
// 'parse_mode' => 'Markdown', // DISABLED
```

### OpenAI API

**Model:** GPT-3.5 Turbo
**Purpose:** Parse natural language into structured data

#### Input Example
```
"check avail jan 2-3"
```

#### Output Example
```json
{
  "intent": "check_availability",
  "dates": {
    "check_in": "2025-01-02",
    "check_out": "2025-01-03"
  },
  "room": null,
  "guest": null,
  "property": null
}
```

#### Supported Intents
- `check_availability` ✅ Implemented
- `create_booking` 🚧 Partial
- `modify_booking` 🚧 Planned
- `cancel_booking` 🚧 Planned
- `view_bookings` 🚧 Planned
- `help` ✅ Implemented
- `unknown` ✅ Implemented

---

## Bot Usage

### Authorization Flow

1. User starts conversation with bot
2. Bot checks if user is authorized (phone number lookup)
3. If not authorized, bot requests phone number
4. User shares contact via Telegram
5. System verifies against `authorized_staff` table
6. Access granted or denied

### Check Availability (Implemented)

**Commands:**
```
check avail jan 2-3
check availability from january 2 to january 3
are there any rooms available jan 2-3?
show me available rooms for jan 2 to jan 3
```

**Bot Response:**
```
Available Rooms
Check-in: 2025-01-02
Check-out: 2025-01-03

Room 12 - Double or Twin
Type: Double
Price: $50/night

Room 14 - Standard Queen
Type: Double
Price: $50/night

... [more rooms] ...

To book, use: book room [NUMBER] under [NAME] [DATES] tel [PHONE] email [EMAIL]
```

### Create Booking (Planned - Not Yet Integrated)

**Commands:**
```
book room 12 under John Walker jan 2-3 tel +1 4578 78 78 email ok@ok.com
book the double room 14 from jan 2 to jan 3 for John Walker phone +1234567890
```

**Expected Response:**
```
✅ Booking Confirmed!

Booking ID: #123456
Property: Jahongir Premium Hotel
Room: Unit 12 - Double or Twin
Guest: John Walker
Check-in: 2025-01-02
Check-out: 2025-01-03
Total: $100.00

Contact: +1 4578 78 78
Email: ok@ok.com
```

---

## Setup & Configuration

### Environment Variables

Required in `/var/www/jahongirnewapp/.env`:

```env
# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token_here

# Beds24 API
BEDS24_API_V2_TOKEN=your_beds24_token_here

# OpenAI
OPENAI_API_KEY=your_openai_key_here

# Application
APP_URL=https://jahongir-app.uz
```

### Initial Setup

```bash
# SSH to server
ssh -p 2222 root@62.72.22.205

# Navigate to project
cd /var/www/jahongirnewapp

# Run migrations
php artisan migrate

# Set Telegram webhook
php artisan tinker
>>> use App\Services\TelegramBotService;
>>> $telegram = app(TelegramBotService::class);
>>> $telegram->setWebhook('https://jahongir-app.uz/api/booking/bot/webhook');

# Verify webhook
>>> $telegram->getWebhookInfo();
```

### Database Seeding

Room mappings are already populated. To repopulate:

```bash
# On server
cd /var/www/jahongirnewapp
php /tmp/populate_rooms.php
```

### Add Authorized Staff

```php
use App\Models\AuthorizedStaff;

AuthorizedStaff::create([
    'phone_number' => '+998915550808',
    'first_name' => 'Staff',
    'last_name' => 'Member',
    'is_active' => true,
    'authorized_at' => now(),
]);
```

---

## Code Structure

### Key Files

```
app/
├── Http/Controllers/
│   └── BookingWebhookController.php (Lines 15-30)
│       - Receives Telegram webhooks
│       - Dispatches ProcessBookingMessage job
│
├── Jobs/
│   └── ProcessBookingMessage.php (Lines 50-200)
│       - Main business logic
│       - Intent routing
│       - Availability checking (Lines 100-150)
│       - Response formatting
│
├── Services/
│   ├── TelegramBotService.php (Lines 20-60)
│   │   - sendMessage() - Sends replies to users
│   │   - Markdown disabled on line 24
│   │
│   ├── Beds24BookingService.php (Lines 80-120)
│   │   - checkAvailability() - Main availability logic
│   │   - createBooking() - Exists, not integrated
│   │   - modifyBooking() - Exists, not integrated
│   │   - cancelBooking() - Exists, not integrated
│   │
│   ├── BookingIntentParser.php (Lines 30-100)
│   │   - parseMessage() - OpenAI integration
│   │   - Returns structured intent data
│   │
│   └── StaffAuthorizationService.php
│       - verifyTelegramUser()
│       - linkPhoneNumber()
│
└── Models/
    ├── AuthorizedStaff.php
    ├── RoomUnitMapping.php
    ├── StaffBookingRequest.php
    └── BotManagedBooking.php
```

### Critical Code Sections

**ProcessBookingMessage::handleCheckAvailability()** (app/Jobs/ProcessBookingMessage.php)
```php
protected function handleCheckAvailability(array $parsed, $beds24): string
{
    // 1. Extract dates
    $dates = $parsed['dates'] ?? null;

    // 2. Get all configured rooms
    $rooms = RoomUnitMapping::all();

    // 3. Check which rooms are booked (Beds24 API call)
    $availability = $beds24->checkAvailability($checkIn, $checkOut, $roomIds);
    $bookedRoomIds = $availability['bookedRoomIds'] ?? [];

    // 4. Filter out booked rooms
    $availableRooms = $rooms->reject(function($room) use ($bookedRoomIds) {
        return in_array($room->room_id, $bookedRoomIds);
    });

    // 5. Format and return response
    return $response;
}
```

**Beds24BookingService::checkAvailability()** (app/Services/Beds24BookingService.php)
```php
public function checkAvailability(string $checkIn, string $checkOut, ?array $roomIds = null): array
{
    // Query /bookings endpoint
    $response = Http::withHeaders([
        'token' => $this->token,
        'accept' => 'application/json',
    ])->get($this->apiUrl . '/bookings', [
        'arrival' => $checkIn,
        'departure' => $checkOut,
        'roomId' => implode(',', $roomIds)
    ]);

    // Extract booked room IDs
    $bookedRoomIds = [];
    foreach ($result['data'] as $booking) {
        if (isset($booking['roomId'])) {
            $bookedRoomIds[] = $booking['roomId'];
        }
    }

    return [
        'success' => true,
        'bookedRoomIds' => array_unique($bookedRoomIds),
        'totalBookings' => $result['count'] ?? 0
    ];
}
```

---

## Troubleshooting

### Bot Not Responding

**Check webhook status:**
```bash
php artisan tinker
>>> use App\Services\TelegramBotService;
>>> app(TelegramBotService::class)->getWebhookInfo();
```

**Check logs:**
```bash
tail -f /var/www/jahongirnewapp/storage/logs/laravel.log
```

**Check recent requests:**
```bash
php artisan tinker
>>> DB::table('staff_booking_requests')->latest()->limit(5)->get();
```

### Only 2 Rooms Showing

**Issue:** Room mappings not populated

**Solution:**
```bash
php /tmp/populate_rooms.php
# Should show 34 rooms total
```

### "Occupancy not defined" Error

**This is expected** - the bot no longer uses `/inventory/rooms/offers` endpoint. Current implementation uses `/bookings` endpoint successfully.

### Markdown Parsing Errors

**Error:** `Bad Request: can't parse entities`

**Solution:** Already fixed - markdown parsing disabled in TelegramBotService.php line 24

### Database Constraint Violations

**Error:** Duplicate entry for `unit_property_room_unique`

**Cause:** Attempting to insert same `(unit_name, property_id, room_id)` combination

**Solution:** The unique constraint allows:
- Same unit names across different properties ✓
- Same unit names for different room types ✓

### Authorization Failed

**Check authorized users:**
```bash
php artisan tinker
>>> DB::table('authorized_staff')->where('is_active', true)->get();
```

**Add user:**
```bash
>>> DB::table('authorized_staff')->insert([
    'phone_number' => '+998915550808',
    'is_active' => true,
    'authorized_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);
```

---

## Next Phase - TODO

### High Priority

1. **✅ Complete Booking Creation Flow**
   - [ ] Integrate `createBooking()` into ProcessBookingMessage job
   - [ ] Add booking confirmation response format
   - [ ] Store booking in `bot_managed_bookings` table
   - [ ] Handle booking failures gracefully

2. **✅ Implement Booking Modification**
   - [ ] Parse modification commands via OpenAI
   - [ ] Validate booking ID exists
   - [ ] Call `modifyBooking()` service method
   - [ ] Send confirmation message

3. **✅ Implement Booking Cancellation**
   - [ ] Parse cancellation commands
   - [ ] Validate booking ID exists
   - [ ] Call `cancelBooking()` service method
   - [ ] Update local database status
   - [ ] Send confirmation message

4. **✅ Implement View Bookings**
   - [ ] Query `bot_managed_bookings` table
   - [ ] Filter by staff member
   - [ ] Filter by date range
   - [ ] Format booking list response

### Medium Priority

5. **Multi-Property Filtering**
   - [ ] Parse property specification ("at premium", "at jahongir")
   - [ ] Filter rooms by property in availability check
   - [ ] Handle property disambiguation for duplicate unit names

6. **Price Calculations**
   - [ ] Calculate total based on number of nights
   - [ ] Handle different rate types (if configured in Beds24)
   - [ ] Show price breakdown in booking confirmation

7. **Error Handling Improvements**
   - [ ] Better error messages for common issues
   - [ ] Retry logic for API failures
   - [ ] Graceful degradation when APIs unavailable

### Low Priority

8. **Reporting Features**
   - [ ] Daily booking summary
   - [ ] Weekly occupancy report
   - [ ] Staff activity dashboard

9. **Multi-language Support**
   - [ ] Support Uzbek language
   - [ ] Support Russian language
   - [ ] Auto-detect language preference

10. **Advanced Features**
    - [ ] Booking history search
    - [ ] Guest information management
    - [ ] Notification system for check-ins/check-outs

---

## Development Commands

### Database Queries

```bash
php artisan tinker

# View all room mappings
>>> DB::table('room_unit_mappings')
      ->orderBy('property_id')->orderBy('unit_name')
      ->get();

# Count rooms by property
>>> DB::table('room_unit_mappings')
      ->select('property_name', DB::raw('count(*) as total'))
      ->groupBy('property_name')
      ->get();

# View recent bot requests
>>> DB::table('staff_booking_requests')
      ->latest()->limit(10)->get();

# Check authorized staff
>>> DB::table('authorized_staff')
      ->where('is_active', true)->get();
```

### Git Workflow

```bash
# Current branch
git branch
# Should show: * feature/telegram-bot

# Commit changes
git add .
git commit -m "feat: [description]"

# Push to remote
git push origin feature/telegram-bot

# Create PR to main
# (via GitHub/GitLab UI)
```

### Testing

**Manual test via Telegram:**
1. Open bot in Telegram
2. Send: `check avail jan 2-3`
3. Verify response shows available rooms

**Test via cURL (requires valid chat ID):**
```bash
curl -X POST https://jahongir-app.uz/api/booking/bot/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "update_id": 999,
    "message": {
      "message_id": 1,
      "from": {"id": 123},
      "chat": {"id": 123},
      "text": "check avail jan 2-3"
    }
  }'
```

---

## Contact Information

**Server Access:**
- Host: `62.72.22.205`
- Port: `2222`
- User: `root`
- Path: `/var/www/jahongirnewapp`

**Application:**
- URL: `https://jahongir-app.uz`
- Webhook: `https://jahongir-app.uz/api/booking/bot/webhook`

**Authorized User:**
- Phone: `+998 91 555 08 08`

---

## Changelog

### 2025-10-12 - Phase 1 Complete

**Implemented:**
- ✅ Database schema (4 tables)
- ✅ Phone-based authorization system
- ✅ Telegram Bot API integration
- ✅ Beds24 API V2 integration
- ✅ OpenAI natural language parsing
- ✅ Availability checking system
- ✅ All 34 room units mapped
- ✅ Unique constraint fixes
- ✅ Markdown parsing issue resolved
- ✅ Audit logging system
- ✅ Webhook endpoint and job processing

**Issues Resolved:**
- Fixed "Occupancy not defined" error (switched to /bookings endpoint)
- Fixed markdown entity parsing errors (disabled parse_mode)
- Fixed duplicate unit name constraints (composite unique key)
- Corrected Premium hotel room mappings (units 21, 22, 24)

**Commits:**
- `8ec3553` - docs: Add Telegram bot implementation guide
- `8bbc2b1` - feat: Implement Telegram booking bot with calendar-based availability
- `2f92989` - Fix availability check to use correct Beds24 endpoint

### Next Release - Phase 2 (Planned)

**Planned Features:**
- ⏳ Complete booking creation flow
- ⏳ Booking modification
- ⏳ Booking cancellation
- ⏳ View bookings functionality
- ⏳ Enhanced error handling
- ⏳ Price calculations

---

**Document Version:** 1.0
**Maintained By:** Development Team
**License:** Internal Use Only - Jahongir Hotels

