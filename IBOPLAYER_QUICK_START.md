# iboplayer Quick Start Guide

## Get Your System Working in 3 Steps

### Step 1: Enable Proxy Mode

```bash
php artisan iptv:proxy-mode enable
```

Expected output:
```
✓ Source #1: My M3U Source
  Proxy mode: ENABLED (proxied)

✓ Configuration complete!
```

---

### Step 2: Get Your Connection Info

You need 3 pieces of information:

1. **Server URL**: `https://your-domain.com` (your APP_URL from .env)
2. **Username**: Your IPTV user's username
3. **Password**: Your IPTV user's password

**Example**:
```
Server:   https://novatv.novadevlabs.com
Username: hicham
Password: hicham123
```

---

### Step 3: Configure iboplayer

#### Method 1: Xtream Codes (Recommended)

1. Open iboplayer
2. Go to: **Settings → Add Playlist**
3. Select: **Xtream Codes API**
4. Enter:
   - **Name**: My IPTV
   - **Server URL**: `https://your-domain.com`
   - **Username**: `your_username`
   - **Password**: `your_password`
5. Click **Save**
6. Reload playlist

#### Method 2: M3U URL

1. Open iboplayer
2. Go to: **Settings → Add Playlist**
3. Select: **M3U URL**
4. Enter:
   ```
   https://your-domain.com/get.php?username=your_username&password=your_password
   ```
5. Click **Save**
6. Reload playlist

---

## Verify It's Working

You should now see:
- ✅ **Categories** showing properly
- ✅ **Channels** organized by category
- ✅ **Streams** playing without errors

---

## Troubleshooting

### Still Not Working?

**1. Check proxy mode is enabled:**
```bash
php artisan iptv:proxy-mode status
```

**2. Test the API manually:**
```bash
# Test user authentication
curl "https://your-domain.com/player_api.php?username=USER&password=PASS"

# Should return JSON with user_info and server_info
```

**3. Check Laravel logs:**
```bash
tail -f storage/logs/laravel.log
```

**4. Clear cache:**
```bash
php artisan cache:clear
php artisan iptv:refresh-m3u
```

---

## Common Issues

### "Invalid credentials" error
- Check username and password are correct
- Check user is active: `is_active = 1`
- Check user hasn't expired: `expires_at > now()`

### Categories not showing
- Make sure proxy mode is **enabled**
- Clear cache: `php artisan cache:clear`
- Refresh M3U: `php artisan iptv:refresh-m3u`

### Streams timeout
- Check your M3U source URL is accessible
- Check Laravel logs for upstream errors
- Verify firewall allows outbound connections

---

## Need More Help?

See the full documentation: **IBOPLAYER_PROXY_FIX.md**

Check logs:
```bash
# Laravel application logs
tail -f storage/logs/laravel.log

# Nginx access logs
tail -f /var/log/nginx/access.log

# Nginx error logs
tail -f /var/log/nginx/error.log
```

Test endpoints:
```bash
# Get user info
curl "https://your-domain.com/player_api.php?username=USER&password=PASS"

# Get categories
curl "https://your-domain.com/player_api.php?username=USER&password=PASS&action=get_live_categories"

# Get streams
curl "https://your-domain.com/player_api.php?username=USER&password=PASS&action=get_live_streams"
```

---

## That's It!

You're all set up. Enjoy your IPTV streams with iboplayer! 🎉
