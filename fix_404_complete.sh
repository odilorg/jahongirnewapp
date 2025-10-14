#!/bin/bash

# Complete Fix Script for Start Shift 404 Error
# Run this script to apply all fixes and clear all caches

echo "=========================================="
echo "Start Shift 404 - Complete Fix Script"
echo "=========================================="
echo ""

cd "$(dirname "$0")"

echo "Step 1: Clear all Laravel caches..."
php artisan optimize:clear
php artisan route:clear
php artisan view:clear
php artisan config:clear
php artisan cache:clear
echo "✓ Caches cleared"
echo ""

echo "Step 2: Regenerate autoload files..."
composer dump-autoload -o
echo "✓ Autoload regenerated"
echo ""

echo "Step 3: Clear browser caches (MANUAL STEP REQUIRED)..."
echo "  -> Press Ctrl+Shift+R in your browser"
echo "  -> Or use Incognito/Private mode"
echo ""

echo "Step 4: Verify route registration..."
php artisan route:list --name=start-shift
echo ""

echo "Step 5: Check for syntax errors..."
php -l app/Filament/Resources/CashierShiftResource/Pages/StartShift.php
echo ""

echo "=========================================="
echo "Fix Complete!"
echo "=========================================="
echo ""
echo "Next Steps:"
echo "1. Clear your browser cache (Ctrl+Shift+R)"
echo "2. Navigate to: http://127.0.0.1:8000/admin/cashier-shifts"
echo "3. Click the 'START SHIFT' button"
echo ""
echo "If still 404:"
echo "- Check storage/logs/laravel.log for new errors"
echo "- Verify you're logged in as a user"
echo "- Try accessing directly: http://127.0.0.1:8000/admin/cashier-shifts/start-shift"
echo ""
