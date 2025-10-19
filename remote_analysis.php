<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\TelegramPosSession;
use App\Models\TelegramPosActivity;

echo "\n========================================\n";
echo "REMOTE PRODUCTION - POS BOT ANALYSIS\n";
echo "========================================\n\n";

// 1. Check users with roles
echo "=== USERS WITH ROLES ===\n";
$users = User::with('roles')->get(['id', 'name', 'email', 'phone_number', 'telegram_pos_user_id', 'pos_bot_enabled']);
foreach($users as $u) {
    $roles = $u->roles->pluck('name')->implode(', ');
    if(!empty($roles)) {
        echo "ID: {$u->id}\n";
        echo "  Name: {$u->name}\n";
        echo "  Email: {$u->email}\n";
        echo "  Phone: " . ($u->phone_number ?? '❌ NULL - MISSING!') . "\n";
        echo "  Roles: {$roles}\n";
        echo "  Telegram POS ID: " . ($u->telegram_pos_user_id ?? 'NULL') . "\n";
        echo "  POS Bot Enabled: " . ($u->pos_bot_enabled ? '✅ Yes' : '❌ No') . "\n";
        echo "  ---\n";
    }
}

// 2. Check sessions
echo "\n=== TELEGRAM POS SESSIONS ===\n";
$sessions = TelegramPosSession::with('user')->get();
if($sessions->count() > 0) {
    foreach($sessions as $s) {
        $userName = $s->user ? $s->user->name : 'No User';
        $expired = $s->isExpired() ? '❌ EXPIRED' : '✅ ACTIVE';
        echo "Session ID: {$s->id}\n";
        echo "  Telegram User ID: {$s->telegram_user_id}\n";
        echo "  User: {$userName} (ID: " . ($s->user_id ?? 'NULL') . ")\n";
        echo "  State: {$s->state}\n";
        echo "  Status: {$expired}\n";
        echo "  ---\n";
    }
} else {
    echo "No sessions found\n";
}

// 3. Check recent activity
echo "\n=== RECENT TELEGRAM POS ACTIVITY (Last 15) ===\n";
$activities = TelegramPosActivity::orderBy('created_at', 'desc')->limit(15)->get();
if($activities->count() > 0) {
    foreach($activities as $a) {
        echo $a->created_at->format('Y-m-d H:i:s') . " | ";
        echo "User ID: " . ($a->user_id ?? 'NULL') . " | ";
        echo "Action: {$a->action} | ";
        echo "TG ID: " . ($a->telegram_user_id ?? 'NULL') . "\n";
        if($a->details) {
            echo "    Details: {$a->details}\n";
        }
    }
} else {
    echo "❌ No activity logs found - Bot has never received requests\n";
}

// 4. Critical issues summary
echo "\n=== CRITICAL ISSUES SUMMARY ===\n";
$usersWithoutPhone = User::whereHas('roles', function($q) {
    $q->whereIn('name', ['cashier', 'manager', 'super_admin']);
})->whereNull('phone_number')->count();

if ($usersWithoutPhone > 0) {
    echo "❌ CRITICAL: {$usersWithoutPhone} users have NULL phone numbers\n";
    echo "   → Users cannot authenticate via Telegram bot\n\n";
}

$disabledUsers = User::whereHas('roles', function($q) {
    $q->whereIn('name', ['cashier', 'manager']);
})->where('pos_bot_enabled', false)->count();

if ($disabledUsers > 0) {
    echo "⚠️  WARNING: {$disabledUsers} users have pos_bot_enabled = false\n";
    echo "   → These users will be denied access\n\n";
}

echo "\nAnalysis complete.\n";
