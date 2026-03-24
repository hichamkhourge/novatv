#!/bin/sh
# Health check and diagnostic script for IPTV Provider

echo "=== IPTV Provider Health Check ==="
echo ""

# Check environment
echo "1. Environment Variables:"
echo "   APP_NAME: ${APP_NAME:-NOT SET}"
echo "   APP_ENV: ${APP_ENV:-NOT SET}"
echo "   APP_DEBUG: ${APP_DEBUG:-NOT SET}"
echo "   APP_URL: ${APP_URL:-NOT SET}"
echo "   DB_HOST: ${DB_HOST:-NOT SET}"
echo ""

# Check database connectivity
echo "2. Database Connection:"
if php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; then
    echo "   ✓ Database connection successful"
else
    echo "   ✗ Database connection failed"
fi
echo ""

# Check Redis connectivity
echo "3. Redis Connection:"
if php -r "try { (new \Redis())->connect('${REDIS_HOST}', ${REDIS_PORT}); echo 'OK'; } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
    echo "   ✓ Redis connection successful"
else
    echo "   ✗ Redis connection failed"
fi
echo ""

# Check PHP-FPM
echo "4. PHP-FPM Status:"
if php-fpm -t 2>/dev/null; then
    echo "   ✓ PHP-FPM configuration is valid"
else
    echo "   ✗ PHP-FPM configuration has errors"
fi
echo ""

# Check Laravel
echo "5. Laravel Status:"
if php artisan --version 2>/dev/null; then
    echo "   ✓ Laravel is accessible"
else
    echo "   ✗ Laravel is not accessible"
fi
echo ""

# Check file permissions
echo "6. File Permissions:"
if [ -w "/var/www/html/storage" ]; then
    echo "   ✓ Storage directory is writable"
else
    echo "   ✗ Storage directory is not writable"
fi
echo ""

echo "=== End of Health Check ==="
