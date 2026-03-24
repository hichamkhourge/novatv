# Troubleshooting Guide - IPTV Provider

## 502 Bad Gateway Error

A 502 Bad Gateway error means nginx cannot connect to the PHP-FPM app container.

### Step 1: Check Container Status

```bash
docker ps -a | grep iptv
```

**Look for:**
- Is `iptv_app` container running or constantly restarting?
- Status should be "Up" not "Restarting" or "Exited"

### Step 2: Check App Container Logs

```bash
docker logs <app-container-name> --tail=100
```

**What to look for:**

✅ **Good** - Should see:
```
IPTV Provider Container Starting...
✓ Database is ready!
✓ Success
Initialization complete!
Starting PHP-FPM...
[NOTICE] fpm is running, pid 1
[NOTICE] ready to handle connections
```

❌ **Bad** - If you see:
- `Failed to parse dotenv file` → Environment variable has quotes (remove them!)
- `Database not ready after 60 seconds` → Database issue
- Container exits immediately → Check error messages
- Composer errors → Code volume not mounted

### Step 3: Check Nginx Logs

```bash
docker logs <nginx-container-name> --tail=50
```

**Look for:**
- `connect() failed (111: Connection refused)` → App container not running
- `upstream: "fastcgi://app:9000"` → Check if app service name is correct

### Step 4: Test App Container Directly

```bash
# Enter the app container
docker exec -it <app-container-name> sh

# Check if PHP-FPM is running
ps aux | grep php-fpm

# Check environment variables
env | grep APP_NAME
# Should show: APP_NAME=IPTVProvider (no quotes!)

# Test database connection
php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" && echo "DB OK"

# Test Laravel
php artisan --version

# Exit container
exit
```

### Step 5: Test Connection from Nginx to App

```bash
docker exec <nginx-container-name> wget -O- http://app:9000/ 2>&1 | head -20
```

**Should see:** HTML output or error details (not "connection refused")

### Step 6: Run Health Check Script

```bash
docker exec <app-container-name> sh /var/www/html/docker/healthcheck.sh
```

This will test:
- Environment variables
- Database connection
- Redis connection
- PHP-FPM configuration
- Laravel accessibility
- File permissions

---

## Common Issues and Fixes

### Issue 1: APP_NAME Parsing Error

**Error:**
```
Failed to parse dotenv file. Encountered unexpected whitespace at [IPTV Provider]
```

**Cause:** Quotes around APP_NAME value

**Fix:**
```env
# WRONG:
APP_NAME="IPTV Provider"

# CORRECT:
APP_NAME=IPTVProvider
```

### Issue 2: App Container Keeps Restarting

**Check why it's restarting:**
```bash
docker logs <app-container-name> --tail=100
```

**Common causes:**
- Database not accessible
- Composer autoload failed
- PHP syntax error
- Missing environment variables

**Emergency fix - Use minimal entrypoint:**

In your Dockerfile or docker-compose.yml, temporarily change:
```dockerfile
# From:
ENTRYPOINT ["/entrypoint.sh"]

# To:
ENTRYPOINT ["/var/www/html/docker/entrypoint.minimal.sh"]
```

This skips all initialization and just starts PHP-FPM.

### Issue 3: Database Connection Failed

**Error:**
```
Database not ready after 60 seconds
```

**Check database:**
```bash
# Check if postgres container is running
docker ps | grep postgres

# Test database from app container
docker exec <app-container-name> php -r "new PDO('pgsql:host=postgres;dbname=iptv', 'iptv_user', 'secure_password_here');" && echo "Connected!"
```

**Fix:** Check DB credentials in environment variables

### Issue 4: Vendor Directory Not Found

**Error:**
```
composer.json not found
```

**Cause:** Code volume not mounted properly

**Check:**
```bash
docker exec <app-container-name> ls -la /var/www/html/
```

**Should see:** composer.json, app/, database/, etc.

**Fix:** Ensure docker-compose.yml has:
```yaml
volumes:
  - .:/var/www/html
  - /var/www/html/vendor
```

### Issue 5: Permission Denied Errors

**Error:**
```
Unable to write to storage directory
```

**Fix:**
```bash
docker exec <app-container-name> chown -R www-data:www-data /var/www/html/storage
docker exec <app-container-name> chmod -R 775 /var/www/html/storage
```

---

## Debugging in Dokploy

### View Real-Time Logs

In Dokploy dashboard:
1. Select your application
2. Go to "Logs" tab
3. Select the container (app, nginx, postgres)
4. Watch logs in real-time during deployment

### Access Container Terminal

In Dokploy:
1. Go to your application
2. Click "Terminal" or "Console"
3. Select the container
4. Run diagnostic commands

### Force Rebuild

If changes aren't taking effect:
1. In Dokploy, click "Settings"
2. Enable "Rebuild on deploy"
3. Redeploy

---

## Emergency Recovery Steps

### 1. Use Minimal Entrypoint

Create a temporary docker-compose override:

```yaml
# docker-compose.override.yml
version: '3.8'
services:
  app:
    entrypoint: ["/var/www/html/docker/entrypoint.minimal.sh"]
```

This will start PHP-FPM without any initialization.

### 2. Manually Initialize

Once container is running with minimal entrypoint:

```bash
docker exec <app-container-name> composer dump-autoload --optimize --no-dev
docker exec <app-container-name> php artisan config:cache
docker exec <app-container-name> php artisan route:cache
docker exec <app-container-name> php artisan migrate --force
```

### 3. Check Traefik Routing (Dokploy)

```bash
# View Traefik configuration
docker logs <traefik-container> | grep iptv

# Check if service is registered
docker exec <traefik-container> traefik healthcheck
```

---

## Getting Help

When asking for help, provide:

1. **Container status:**
```bash
docker ps -a | grep iptv
```

2. **App logs (last 100 lines):**
```bash
docker logs <app-container-name> --tail=100
```

3. **Nginx logs:**
```bash
docker logs <nginx-container-name> --tail=50
```

4. **Environment check:**
```bash
docker exec <app-container-name> env | grep -E "APP_NAME|APP_DEBUG|APP_URL|DB_"
```

5. **Health check output:**
```bash
docker exec <app-container-name> sh /var/www/html/docker/healthcheck.sh
```
