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
echo "   APP_ENV: ${APP_ENV:-NOT SET}"
echo "   APP_DEBUG: ${APP_DEBUG:-NOT SET}"
echo "   DB_HOST: ${DB_HOST:-NOT SET}"
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
        echo "   Attempt $i/30 - Database not ready yet, waiting..."
        sleep 2
    fi
done

if [ $DB_READY -eq 0 ]; then
    echo "   ⚠ Database not ready after 60 seconds, starting anyway..."
fi
echo ""

# Composer autoload optimization
echo "3. Optimizing autoloader..."
run_cmd "Running composer dump-autoload" "composer dump-autoload --optimize --no-dev --no-interaction"
echo ""

# Clear caches
echo "4. Clearing caches..."
run_cmd "Clearing config cache" "php artisan config:clear"
run_cmd "Clearing route cache" "php artisan route:clear"
run_cmd "Clearing view cache" "php artisan view:clear"
run_cmd "Clearing app cache" "php artisan cache:clear"
echo ""

# Only cache if we successfully cleared
echo "5. Building caches..."
run_cmd "Caching configuration" "php artisan config:cache"
run_cmd "Caching routes" "php artisan route:cache"
run_cmd "Caching views" "php artisan view:cache"
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

    echo "8. Seeding admin user..."
    run_cmd "Running AdminSeeder" "php artisan db:seed --class=AdminSeeder --force"
    echo ""
else
    echo "7. Skipping migrations (database not ready)"
    echo "8. Skipping seeding (database not ready)"
    echo ""
fi

# Final check
echo "9. Final checks..."
run_cmd "Testing PHP-FPM config" "php-fpm -t"
run_cmd "Testing Laravel" "php artisan --version"
echo ""

echo "======================================="
echo "Initialization complete!"
echo "Starting PHP-FPM..."
echo "======================================="
echo ""

# Start PHP-FPM
exec "$@"
