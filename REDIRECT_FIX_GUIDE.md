# 302 Redirect Fix - Complete Guide

## Problem Identified

Your streams were failing because:

1. ✅ Nginx proxy working correctly
2. ✅ Authentication working
3. ✅ Upstream URL resolved
4. ❌ **Upstream returning HTTP 302 redirects**
5. ❌ **Nginx passing 302 to client instead of following redirect**
6. ❌ **Players (iboplayer, IPTV Smarters, VLC) can't handle redirects**

### Evidence from Logs

```
10.0.1.16 - [25/Mar/2026:20:20:06 +0000] "GET /live/Yanis/yanis/...ts HTTP/1.1" 302
```

**Status: 302** = Redirect (not 200 OK)
**Bytes: 770** = Tiny response (redirect header, not video data)

---

## The Fix

### What Changed

**Modified: `app/Http/Controllers/AuthController.php`**

Added `followRedirects()` method that:
- Makes HEAD request to upstream URL
- Detects 301/302/303/307/308 redirects
- Follows Location header (up to 5 redirects)
- Returns final direct URL to nginx
- Handles relative and absolute redirects
- Prevents redirect loops

### How It Works Now

```
┌─────────────────────────────────────────────────────────┐
│ Before (BROKEN):                                        │
│                                                         │
│ Player → Nginx → Auth → Get http://ugeen.live:8080/... │
│                    ↓                                    │
│         Nginx proxies to ugeen.live                     │
│                    ↓                                    │
│         Upstream returns: HTTP 302 → http://cdn.com/... │
│                    ↓                                    │
│         Nginx passes 302 to player                      │
│                    ↓                                    │
│         Player can't handle redirect → FAIL ❌          │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│ After (FIXED):                                          │
│                                                         │
│ Player → Nginx → Auth → Get http://ugeen.live:8080/... │
│                    ↓                                    │
│         PHP follows redirects:                          │
│         • HEAD http://ugeen.live:8080/... → 302        │
│         • HEAD http://cdn.com/stream → 200 OK          │
│         • Returns final URL: http://cdn.com/stream      │
│                    ↓                                    │
│         Nginx proxies to http://cdn.com/stream (direct) │
│                    ↓                                    │
│         Upstream returns: HTTP 200 + video data         │
│                    ↓                                    │
│         Player receives video → SUCCESS ✅              │
└─────────────────────────────────────────────────────────┘
```

---

## How to Test

### Step 1: Clear Cache (Important!)

```bash
# Clear Laravel cache
php artisan cache:clear

# Clear route cache
php artisan route:clear

# Clear config cache
php artisan config:clear
```

### Step 2: Restart Services

```bash
# Restart PHP-FPM (to load new code)
docker-compose restart app

# Restart nginx (just in case)
docker-compose restart nginx
```

### Step 3: Monitor Logs in Real-Time

Open **3 terminals**:

**Terminal 1 - Laravel logs (watch for "Following redirect"):**
```bash
docker-compose logs -f app | grep -E "(Stream auth|Following redirect|Final URL)"
```

**Terminal 2 - Nginx debug logs:**
```bash
docker-compose exec nginx tail -f /var/log/nginx/stream_debug.log
```

**Terminal 3 - Nginx error logs:**
```bash
docker-compose exec nginx tail -f /var/log/nginx/stream_error.log
```

### Step 4: Test in iboplayer

1. Open iboplayer
2. Navigate to a channel
3. Click to play

### Step 5: Check the Logs

**Expected in Laravel logs:**

```
[2026-03-25 20:30:15] production.DEBUG: Following redirect
{
    "from":"http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/47",
    "to":"http://cdn.ugeen.live/stream/abc123.m3u8",
    "status":302,
    "redirect_number":1
}

[2026-03-25 20:30:15] production.DEBUG: Final URL resolved
{
    "original":"http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/47",
    "final":"http://cdn.ugeen.live/stream/abc123.m3u8",
    "redirect_count":1
}

[2026-03-25 20:30:15] production.INFO: Stream auth: Success
{
    "username":"Yanis",
    "stream_id":"...",
    "upstream_original":"http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/47",
    "upstream_final":"http://cdn.ugeen.live/stream/abc123.m3u8",
    "redirected":true,
    "components":{
        "scheme":"http",
        "host":"cdn.ugeen.live",
        "port":8080,
        "path":"/stream/abc123.m3u8"
    }
}
```

**Expected in Nginx debug logs:**

```
10.0.1.16 - [25/Mar/2026:20:30:16 +0000] "GET /live/Yanis/yanis/...ts HTTP/1.1" 200
user=Yanis
stream_id=...
upstream=http://cdn.ugeen.live/stream/abc123.m3u8
bytes=10485760
```

**Notice:**
- ✅ Status: **200** (not 302!)
- ✅ Upstream: **Final CDN URL** (not original ugeen.live URL)
- ✅ Bytes: **10485760** (10MB - actual video data, not 770 bytes!)

---

## Success Criteria

✅ Laravel logs show "Following redirect"
✅ Laravel logs show "Final URL resolved"
✅ Laravel logs show `"redirected": true`
✅ Nginx debug log shows **HTTP 200** (not 302)
✅ Nginx debug log shows **large bytes sent** (MB, not 770 bytes)
✅ **Stream plays in iboplayer!**

---

## Troubleshooting

### Issue: Still seeing HTTP 302 in nginx debug log

**Cause**: PHP code not reloaded or cache not cleared

**Solution**:
```bash
php artisan cache:clear
docker-compose restart app
```

### Issue: Laravel logs show "Failed to follow redirects"

**Cause**: Network issue or timeout

**Debug**:
```bash
# Test redirect manually from server
docker-compose exec app curl -I "http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/47"

# Should see:
# HTTP/1.1 302 Found
# Location: http://...
```

### Issue: Logs show "Too many redirects"

**Cause**: Redirect loop (A → B → A → B...)

**Solution**: This is an upstream server issue. The fix returns the last URL anyway, so nginx will try it.

### Issue: Stream still doesn't play

**Check:**
1. What status code in nginx log? (should be 200)
2. How many bytes sent? (should be > 1MB for working stream)
3. Does final URL work directly?

```bash
# Get final URL from Laravel logs and test it
curl -I "http://FINAL_URL_FROM_LOGS"

# Should return 200 OK with video content-type
```

---

## Performance Impact

**Minimal overhead:**
- HEAD request: ~50-200ms (only on first auth, then cached for 60s)
- Cached for 60 seconds per stream_id
- Only runs during authentication, not for every chunk

**Cache duration:** Stream URL resolution is already cached for 60 seconds, so redirect resolution only happens once per minute per stream.

---

## What to Expect

### Before Fix:
```
Status: 302
Bytes: 770
Result: Stream doesn't play
```

### After Fix:
```
Status: 200
Bytes: 10,485,760 (10MB+)
Result: Stream plays perfectly!
```

---

## Advanced: Manual Testing

### Test redirect resolution directly:

```bash
# Test with PHP artisan tinker
php artisan tinker
```

Then in tinker:
```php
$controller = new \App\Http\Controllers\AuthController(
    app(\App\Services\M3UParserService::class),
    app(\App\Services\ConnectionTrackerService::class)
);

// Use reflection to call private method
$method = new \ReflectionMethod($controller, 'followRedirects');
$method->setAccessible(true);

$url = 'http://ugeen.live:8080/Ugeen_VIPR5WrbA/pnasWG/47';
$finalUrl = $method->invoke($controller, $url);

echo "Original: $url\n";
echo "Final: $finalUrl\n";
```

---

## Next Steps

1. **Clear cache** (Step 1)
2. **Restart services** (Step 2)
3. **Open 3 log terminals** (Step 3)
4. **Test in iboplayer** (Step 4)
5. **Watch logs** (Step 5)
6. **Celebrate when it works!** 🎉

---

## Summary

The fix is simple but crucial:

**Before:** Nginx tried to proxy redirects → Failed
**After:** PHP follows redirects first → Nginx gets final URL → Success!

This is the standard solution for IPTV reverse proxies that deal with upstream servers using redirects for CDN/load balancing.

**Your streams should now work perfectly in all players!** 🚀
