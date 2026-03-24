#!/bin/sh
set -e

echo "Starting IPTV Provider initialization..."

# Wait for database to be ready
echo "Waiting for database..."
until php artisan db:monitor --max-attempts=1 2>/dev/null || php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    echo "Database is unavailable - sleeping"
    sleep 2
done
echo "Database is ready!"

# Complete composer setup (package discovery and optimization)
echo "Optimizing autoloader..."
composer dump-autoload --optimize --no-dev --no-interaction

# Clear any existing caches first
echo "Clearing caches..."
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan cache:clear || true

# Laravel optimizations
echo "Caching configuration..."
php artisan config:cache || echo "Config cache failed, continuing..."
php artisan route:cache || echo "Route cache failed, continuing..."
php artisan view:cache || echo "View cache failed, continuing..."

# Run Filament upgrade (publishes assets)
echo "Publishing Filament assets..."
php artisan filament:upgrade || echo "Filament upgrade not needed, continuing..."

# Database setup
echo "Running migrations..."
php artisan migrate --force || echo "Migrations failed or already up to date"

echo "Seeding admin user..."
php artisan db:seed --class=AdminSeeder --force || echo "Admin seeder failed or already exists"

echo "Initialization complete. Starting PHP-FPM..."
exec "$@"
