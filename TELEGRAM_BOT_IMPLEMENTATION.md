# Telegram Booking Bot

See commit 8bbc2b1 for implementation details.

## Key Features
- Calendar-based availability (shows rooms available for ENTIRE stay)
- Single and multi-room bookings with grouping
- Property disambiguation for duplicate room numbers
- Natural language processing via OpenAI

## Commands
- check avail oct 16-19
- book room 4 under John Doe oct 20-21 tel +998901234567 email test@test.com
- book room 12 at Premium under Jane jan 5-7 tel +123

## Troubleshooting
- Shows all rooms: php artisan queue:restart && php artisan optimize:clear
- No rooms: php artisan db:seed --class=RoomUnitMappingSeeder --force

## Files
- app/Jobs/ProcessBookingMessage.php
- app/Services/Beds24BookingService.php
- app/Services/BookingIntentParser.php

