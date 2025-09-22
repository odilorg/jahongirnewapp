#!/bin/bash

# Cash Management System Deployment Script
# Run this script on your server: /var/www/jahongirnewapp

echo "🚀 Starting Cash Management System Deployment..."

# Navigate to project directory
cd /var/www/jahongirnewapp

# Pull latest changes
echo "📥 Pulling latest changes..."
git pull origin main

# Install/Update dependencies
echo "📦 Installing dependencies..."
composer install --no-dev --optimize-autoloader

# Run database migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Seed the database
echo "🌱 Seeding database..."
php artisan db:seed --class=CashManagementRolePermissionSeeder --force
php artisan db:seed --class=CashDrawerSeeder --force
php artisan db:seed --class=ExchangeRateSeeder --force

# Clear and optimize caches
echo "🧹 Clearing and optimizing caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Set proper permissions
echo "🔐 Setting permissions..."
chown -R www-data:www-data /var/www/jahongirnewapp
chmod -R 755 /var/www/jahongirnewapp
chmod -R 775 /var/www/jahongirnewapp/storage
chmod -R 775 /var/www/jahongirnewapp/bootstrap/cache

# Restart web server
echo "🔄 Restarting web server..."
systemctl restart apache2
# systemctl restart nginx  # Uncomment if using Nginx

echo "✅ Deployment completed successfully!"
echo "🎉 Cash Management System is now live with:"
echo "   - Multi-currency support (UZS, EUR, USD, RUB)"
echo "   - Complex transaction handling"
echo "   - Comprehensive reporting system"
echo "   - Role-based access control"
echo ""
echo "Access your application at: http://your-domain/admin"
