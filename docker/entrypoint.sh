#!/bin/sh
# Entrypoint script for IPTV Provider
# Note: We don't use 'set -e' to allow PHP-FPM to start even if some setup steps fail

echo "======================================="
echo "IPTV Provider Container Starting..."
echo "======================================="
echo ""

# Function to run commands with error handling
run_cmd() {
    echo "→ $1"
    if eval "$2" 2>&1; then
        echo "  ✓ Success"
        return 0
    else
        echo "  ✗ Failed (continuing anyway)"
        return 1
    fi
}

# Check critical environment variables
echo "1. Checking environment..."
echo "   APP_NAME: ${APP_NAME:-NOT SET}"
echo "   APP_ENV:  ${APP_ENV:-NOT SET}"
echo "   APP_DEBUG: ${APP_DEBUG:-NOT SET}"
echo "   DB_HOST:  ${DB_HOST:-NOT SET}"
echo ""

# Wait for database (with timeout)
echo "2. Waiting for database connection..."
DB_READY=0
for i in $(seq 1 30); do
    if php -r "try { new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}'); exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "   ✓ Database is ready!"
        DB_READY=1
        break
    else
        echo "   Attempt $i/30 - Database not ready yet, waiting 2s..."
        sleep 2
    fi
done

if [ $DB_READY -eq 0 ]; then
    echo "   ⚠ Database not ready after 60 seconds, starting anyway..."
fi
echo ""

# Fix storage permissions at runtime (volumes are mounted fresh)
echo "3. Fixing storage permissions..."
run_cmd "Setting storage ownership" "chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache"
run_cmd "Setting storage permissions" "chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache"
echo ""

# NOTE: composer dump-autoload is intentionally skipped here.
# It was already run during `docker build` (RUN composer install).

# Clear caches
echo "4. Clearing caches..."
run_cmd "Clearing config cache" "php artisan config:clear"
run_cmd "Clearing route cache"  "php artisan route:clear"
run_cmd "Clearing view cache"   "php artisan view:clear"
run_cmd "Clearing app cache"    "php artisan cache:clear"
echo ""

# Rebuild caches
echo "5. Building caches..."
run_cmd "Caching configuration" "php artisan config:cache"
run_cmd "Caching routes"        "php artisan route:cache"
run_cmd "Caching views"         "php artisan view:cache"
echo ""

# Publish Filament assets
echo "6. Publishing Filament assets..."
run_cmd "Running filament:upgrade" "php artisan filament:upgrade"
echo ""

# Database migrations (only if DB is ready)
if [ $DB_READY -eq 1 ]; then
    echo "7. Running database migrations..."
    run_cmd "Running migrations" "php artisan migrate --force"
    echo ""

    echo "8. Seeding admin user (first run only)..."
    run_cmd "Running AdminSeeder" "php artisan db:seed --class=AdminSeeder --force 2>&1 | grep -v 'already exists' || true"
    echo ""
else
    echo "7. Skipping migrations (database not ready)"
    echo "8. Skipping seeding (database not ready)"
    echo ""
fi

# Link storage (important for public file access)
echo "9. Linking storage..."
run_cmd "Storage link" "php artisan storage:link --force"
echo ""

# Final checks
echo "10. Final checks..."
run_cmd "Testing PHP-FPM config" "php-fpm -t"
run_cmd "Testing Laravel"        "php artisan --version"
echo ""

echo "======================================="
echo "Initialization complete!"
echo "Starting PHP-FPM..."
echo "======================================="
echo ""

# Start PHP-FPM (replaces this shell process)
exec "$@"
