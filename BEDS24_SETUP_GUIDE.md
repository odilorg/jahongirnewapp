# Beds24 Setup Guide for Telegram Bot

## Step 1: Get Property IDs from Beds24

1. Log in to Beds24: https://beds24.com
2. Go to Settings > Properties
3. Note down the Property ID for each property

## Step 2: Get Room IDs from Beds24

1. In Beds24, go to Rooms/Units
2. Click on each room to see its details
3. The Room ID is usually visible in the URL or room settings

## Step 3: Fill in the Seeder

Open: database/seeders/RoomUnitMappingSeeder.php

Replace all REPLACE_WITH_PROPERTY_ID and REPLACE_WITH_ROOM_ID with actual values from Beds24.

## Step 4: Run the Seeder

```bash
cd /var/www/jahongirnewapp
php artisan db:seed --class=RoomUnitMappingSeeder
```

## Step 5: Test with Telegram Bot

Send to your bot: "check avail dec 10-12"
