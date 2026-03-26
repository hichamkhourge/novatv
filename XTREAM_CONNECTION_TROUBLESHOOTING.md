# Xtream API Connection Troubleshooting

## ✅ TESTED & WORKING

Your Xtream API is **fully functional**! I've tested it with user `hicham` and all endpoints work:

```bash
# User Info - ✅ WORKS
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham"

# Categories - ✅ WORKS
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham&action=get_live_categories"

# Streams - ✅ WORKS
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham&action=get_live_streams"

# M3U Download - ✅ WORKS
curl "https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham"
```

## Common "Invalid Username or Password" Issues

If you're getting authentication errors in your app, try these solutions:

### 1. Different Apps Need Different URL Formats

Different IPTV apps expect the server URL in different formats. Try these variations:

#### TiviMate
```
Server: novatv.novadevlabs.com
OR
Server: https://novatv.novadevlabs.com
Username: hicham
Password: hicham
```

#### IPTV Smarters Pro
```
DNS/Server: https://novatv.novadevlabs.com
OR
DNS/Server: novatv.novadevlabs.com
Username: hicham
Password: hicham
```

#### GSE Smart IPTV
```
URL: https://novatv.novadevlabs.com/player_api.php
Username: hicham
Password: hicham
```

#### Perfect Player
```
Playlist URL: https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham
```

### 2. Try Without HTTPS

Some apps don't handle HTTPS well. Try:

```
Server: http://novatv.novadevlabs.com
Username: hicham
Password: hicham
```

**Note**: This will only work if your server supports HTTP on port 80.

### 3. Try With Port Number

Some apps require explicit port:

```
Server: novatv.novadevlabs.com:443
OR
Server: https://novatv.novadevlabs.com:443
Username: hicham
Password: hicham
```

### 4. Check for Trailing Slashes

Some apps fail with trailing slashes:

❌ **Don't use**: `https://novatv.novadevlabs.com/`
✅ **Use**: `https://novatv.novadevlabs.com`

### 5. Verify Credentials

Double-check:
- Username is exactly: `hicham` (case-sensitive)
- Password is exactly: `hicham` (case-sensitive)
- No extra spaces before/after
- No special characters being auto-corrected by your device

## App-Specific Configuration Guides

### TiviMate (Recommended)

1. Open TiviMate
2. Tap **"+"** to add playlist
3. Select **"Xtream Codes API"**
4. Enter:
   - **Name**: Any name you want
   - **Server**: `novatv.novadevlabs.com`
   - **Username**: `hicham`
   - **Password**: `hicham`
5. If it fails, try again with `https://novatv.novadevlabs.com`

### IPTV Smarters Pro

1. Open IPTV Smarters Pro
2. Tap **"Add User"**
3. Select **"Login with Xtream Codes API"**
4. Enter:
   - **Portal Name**: Any name
   - **DNS/Server**: `novatv.novadevlabs.com`
   - **Username**: `hicham`
   - **Password**: `hicham`
5. If it fails, try:
   - Adding `https://` prefix
   - Adding `:443` port
   - Using IP address instead of domain (if you have it)

### GSE Smart IPTV

1. Open GSE Smart IPTV
2. Tap **"+"** (Add)
3. Select **"Xtream Codes API"**
4. Enter:
   - **Name**: Any name
   - **Server URL**: `https://novatv.novadevlabs.com`
   - **Username**: `hicham`
   - **Password**: `hicham`
5. Tap **"Add"**

### Perfect Player

1. Open Perfect Player
2. Go to **Settings** → **General** → **Playlists**
3. Select **Playlist 1**
4. Enter:
   - **Name**: Any name
   - **URL**: `https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham`
   - **Type**: Auto
5. Save

### VLC Media Player

1. Open VLC
2. Go to **Media** → **Open Network Stream**
3. Enter: `https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham`
4. Click **Play**

Note: VLC works better with M3U format, not Xtream API.

## Manual URL Construction

If your app allows manual URL entry, use this format:

### For Xtream API:
```
https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham
```

### For M3U Playlist:
```
https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham
```

### For Streams:
```
https://novatv.novadevlabs.com/live/hicham/hicham/STREAM_ID.ts
```

## Testing from Browser

You can test directly in your browser:

1. Open your browser
2. Navigate to: `https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham`
3. You should see JSON with user info (not an error)

**Expected Response:**
```json
{
  "user_info": {
    "username": "hicham",
    "password": "hicham",
    "status": "Active",
    "exp_date": 1786533787,
    "max_connections": 1,
    "active_cons": 0,
    "created_at": 1774434197,
    "is_trial": 0
  },
  "server_info": {
    "url": "novatv.novadevlabs.com",
    ...
  }
}
```

## Common Issues & Solutions

### Issue: "Invalid Username or Password"

**Solutions:**
1. Try different URL formats (with/without https, with/without port)
2. Check credentials are exactly correct (case-sensitive)
3. Ensure user is active in admin panel
4. Check user hasn't expired
5. Try a different app to isolate the issue

### Issue: "Server Not Found" or "Connection Failed"

**Solutions:**
1. Check your internet connection
2. Try with HTTP instead of HTTPS
3. Verify domain is accessible: `ping novatv.novadevlabs.com`
4. Check firewall isn't blocking the app
5. Try from different network (mobile data vs WiFi)

### Issue: "Authentication OK but No Channels"

**Solutions:**
1. Check user has M3U source assigned in admin panel
2. Verify M3U source is active
3. Test M3U URL directly: `https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham`
4. Check Redis cache is working
5. Look at Laravel logs: `storage/logs/laravel.log`

### Issue: Channels Load but Won't Play

**Solutions:**
1. Check original stream URLs are valid
2. Test a stream directly in browser/VLC
3. Check connection limit (max_connections)
4. Verify proxy streaming is working
5. Check Docker containers are running: `docker-compose ps`

## Debugging Steps

### Step 1: Test API Directly
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham"
```

Expected: JSON response with user_info and server_info

### Step 2: Test Categories
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham&action=get_live_categories"
```

Expected: JSON array with categories

### Step 3: Test Streams
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham&action=get_live_streams"
```

Expected: JSON array with stream objects

### Step 4: Test M3U
```bash
curl "https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham"
```

Expected: M3U playlist text starting with `#EXTM3U`

### Step 5: Check Logs
```bash
docker-compose logs -f app
```

Look for authentication errors or exceptions

## SSL/HTTPS Issues

If you're having SSL certificate issues:

### Solution 1: Disable SSL Verification (Testing Only)
Some apps have an option to "Allow Untrusted Certificates" or "Disable SSL Verification"

### Solution 2: Use HTTP Instead
Try `http://` instead of `https://` (less secure but might work)

### Solution 3: Check Certificate
```bash
openssl s_client -connect novatv.novadevlabs.com:443 -servername novatv.novadevlabs.com
```

## Still Not Working?

If none of the above work, please provide:

1. **Which app/player you're using** (name and version)
2. **Exact error message** (screenshot if possible)
3. **URL format you tried** (exactly as entered)
4. **Device/OS** (Android, iOS, Windows, etc.)
5. **Result of browser test** (does the browser URL work?)

## Quick Reference Card

**Working URLs (tested):**
- User Info: `https://novatv.novadevlabs.com/player_api.php?username=hicham&password=hicham`
- M3U Playlist: `https://novatv.novadevlabs.com/get.php?username=hicham&password=hicham`

**Credentials:**
- Username: `hicham`
- Password: `hicham`

**Server Formats to Try:**
1. `novatv.novadevlabs.com`
2. `https://novatv.novadevlabs.com`
3. `http://novatv.novadevlabs.com`
4. `novatv.novadevlabs.com:443`
5. `https://novatv.novadevlabs.com:443`

**Best Apps (in order):**
1. TiviMate (Android/Fire TV)
2. IPTV Smarters Pro (Multi-platform)
3. GSE Smart IPTV (iOS/Android)
