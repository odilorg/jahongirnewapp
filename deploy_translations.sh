#!/bin/bash

# Deployment script for translation updates
echo "🚀 Starting deployment of translation updates..."

# 1. Git pull the latest changes
echo "📥 Pulling latest changes from git..."
git pull origin main

# 2. Install/update dependencies (in case new packages were added)
echo "📦 Installing/updating dependencies..."
composer install --no-dev --optimize-autoloader

# 3. Clear all caches (critical for translation changes)
echo "🧹 Clearing application caches..."
php artisan cache:clear
php artisan view:clear
php artisan route:clear
php artisan config:clear

# 4. Optimize for production
echo "⚡ Optimizing for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Set proper permissions (if needed)
echo "🔐 Setting proper permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 6. Restart web server (if using systemd)
echo "🔄 Restarting web server..."
systemctl reload nginx

echo "✅ Deployment complete!"
echo "🌍 Translation system should now be fully functional!"
