#!/bin/sh
set -e

# Complete composer setup (package discovery and optimization)
composer dump-autoload --optimize --no-dev

# Laravel optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Database setup
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force

exec "$@"
