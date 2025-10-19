#!/usr/bin/env php
<?php

/**
 * Diagnostic script to check user location assignments
 * Run this to verify cashier users have locations assigned
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "\n=================================\n";
echo "   USER LOCATION ASSIGNMENTS     \n";
echo "=================================\n\n";

// Get all users with phone numbers (potential bot users)
$users = User::with('locations', 'roles')
    ->whereNotNull('phone_number')
    ->get();

if ($users->isEmpty()) {
    echo "❌ No users found with phone numbers.\n\n";
    exit(1);
}

foreach ($users as $user) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "👤 User: {$user->name} (ID: {$user->id})\n";
    echo "📱 Phone: {$user->phone_number}\n";
    echo "🔑 Roles: " . $user->roles->pluck('name')->join(', ') . "\n";
    echo "🤖 POS Bot Enabled: " . ($user->pos_bot_enabled ? '✅ Yes' : '❌ No') . "\n";
    echo "📍 Locations: ";

    if ($user->locations->isEmpty()) {
        echo "❌ NONE - This is the problem!\n";
        echo "\n💡 FIX: Go to Filament Admin → Users → Edit '{$user->name}' → Assign Locations\n";
    } else {
        echo "✅ " . $user->locations->count() . " location(s)\n";
        foreach ($user->locations as $location) {
            echo "   • {$location->name} (ID: {$location->id})\n";
        }
    }
    echo "\n";
}

// Check location_user pivot table directly
echo "\n=================================\n";
echo "   PIVOT TABLE CHECK             \n";
echo "=================================\n\n";

$pivotData = DB::table('location_user')
    ->join('users', 'location_user.user_id', '=', 'users.id')
    ->join('locations', 'location_user.location_id', '=', 'locations.id')
    ->select('users.name as user_name', 'users.phone_number', 'locations.name as location_name')
    ->get();

if ($pivotData->isEmpty()) {
    echo "❌ No location assignments found in database!\n";
    echo "💡 This means NO users are assigned to ANY locations.\n\n";
} else {
    echo "✅ Found " . $pivotData->count() . " location assignment(s):\n\n";
    foreach ($pivotData as $row) {
        echo "   📍 {$row->user_name} ({$row->phone_number}) → {$row->location_name}\n";
    }
}

echo "\n";
