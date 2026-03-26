# Xtream Codes API Guide

## Overview

Your IPTV Provider application supports Xtream Codes API format, allowing users to connect using standard Xtream-compatible apps and players. This guide explains how to set up and use the Xtream API functionality.

## How It Works

The application converts your M3U sources (URL-based or file-based) into Xtream Codes API format. Users authenticate with their credentials and receive streams proxied through your server.

## Setting Up M3U Source

### Option 1: URL-Based Source

1. Log into the admin panel (Filament)
2. Go to **M3U Sources**
3. Click **Create**
4. Fill in:
   - **Name**: Give your source a name (e.g., "Main IPTV Source")
   - **Source Type**: Select "URL"
   - **URL**: Enter your M3U playlist URL
   - **Use Direct URLs**:
     - `false` (default): Streams are proxied through your server
     - `true`: Original stream URLs are passed to users
   - **Is Active**: Check to enable

### Option 2: File-Based Source

1. Log into the admin panel (Filament)
2. Go to **M3U Sources**
3. Click **Create**
4. Fill in:
   - **Name**: Give your source a name
   - **Source Type**: Select "File"
   - **File**: Upload your M3U file (max 100MB)
   - **Use Direct URLs**: Same as above
   - **Is Active**: Check to enable

## Creating IPTV Users

1. Go to **IPTV Users** in admin panel
2. Click **Create**
3. Fill in user details:
   - **Username**: User's login name
   - **Password**: User's password (stored in plain text)
   - **Email**: Optional
   - **M3U Source**: Select the M3U source this user can access
   - **Max Connections**: Number of simultaneous streams allowed (default: 1)
   - **Is Active**: Enable/disable user
   - **Expires At**: Optional expiration date
   - **Package**: Optional package assignment
   - **Notes**: Internal notes

## Connection Details for End Users

### Server Information

Users should configure their Xtream-compatible app/player with:

```
Host/Server: https://novatv.novadevlabs.com
Port: 443 (HTTPS) or 80 (HTTP)
Username: [assigned username]
Password: [assigned password]
```

**Note**: Based on your `.env`, your domain is `novatv.novadevlabs.com`

### Supported Xtream API Endpoints

#### 1. User & Server Info
```
GET https://novatv.novadevlabs.com/player_api.php?username=USER&password=PASS
```

Returns user information, expiration, connection limits, and server details.

#### 2. Get Live Categories
```
GET https://novatv.novadevlabs.com/player_api.php?username=USER&password=PASS&action=get_live_categories
```

Returns all categories (groups) from the M3U source.

#### 3. Get Live Streams
```
GET https://novatv.novadevlabs.com/player_api.php?username=USER&password=PASS&action=get_live_streams
```

Optional parameter: `category_id` to filter by category.

Returns all live streams with metadata.

#### 4. Stream Playback
```
GET https://novatv.novadevlabs.com/live/USERNAME/PASSWORD/STREAM_ID.ts
```

Proxies the actual video stream. The `STREAM_ID` is returned in the stream list.

#### 5. M3U Playlist Download
```
GET https://novatv.novadevlabs.com/get.php?username=USER&password=PASS
```

Downloads a standard M3U playlist file.

#### 6. VOD/Series/EPG (Placeholder)
These endpoints exist but currently return empty arrays:
- `action=get_vod_categories`
- `action=get_vod_streams`
- `action=get_series_categories`
- `action=get_series`
- `action=get_short_epg`

## Example API Responses

### User Info Response
```json
{
  "user_info": {
    "username": "testuser",
    "password": "testpass",
    "status": "Active",
    "exp_date": 1735689600,
    "max_connections": 1,
    "active_cons": 0,
    "created_at": 1704067200,
    "is_trial": 0
  },
  "server_info": {
    "url": "novatv.novadevlabs.com",
    "port": "80",
    "https_port": "443",
    "server_protocol": "http",
    "rtmp_port": "1935",
    "timezone": "UTC",
    "timestamp_now": 1735689600,
    "time_now": "2026-03-25 10:00:00"
  }
}
```

### Live Categories Response
```json
[
  {
    "category_id": 1,
    "category_name": "Sports",
    "parent_id": 0
  },
  {
    "category_id": 2,
    "category_name": "Movies",
    "parent_id": 0
  }
]
```

### Live Streams Response
```json
[
  {
    "num": 1,
    "name": "ESPN HD",
    "stream_type": "live",
    "stream_id": "a1b2c3d4e5f6",
    "stream_icon": "https://example.com/logo.png",
    "epg_channel_id": "espn.us",
    "added": 1735689600,
    "category_id": "1",
    "custom_sid": "",
    "tv_archive": 0,
    "direct_source": "",
    "tv_archive_duration": 0
  }
]
```

## Compatible Apps & Players

Your Xtream API implementation works with:

- **TiviMate** (Android/Fire TV)
- **IPTV Smarters Pro** (Android/iOS/Fire TV)
- **GSE Smart IPTV** (iOS/Android)
- **Perfect Player** (Android/Windows)
- **VLC Media Player** (with network stream)
- **Kodi** (with IPTV Simple Client add-on)

## Testing the API

### Using cURL

Test authentication:
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=testuser&password=testpass"
```

Get categories:
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=testuser&password=testpass&action=get_live_categories"
```

Get streams:
```bash
curl "https://novatv.novadevlabs.com/player_api.php?username=testuser&password=testpass&action=get_live_streams"
```

### Using a Player

1. Open TiviMate or IPTV Smarters
2. Select "Add Playlist" → "Xtream Codes API"
3. Enter:
   - Server: `https://novatv.novadevlabs.com`
   - Username: Your assigned username
   - Password: Your assigned password
4. Save and load

## Security Features

### Connection Limits
- Maximum simultaneous connections per user (configurable)
- Active connection tracking via `ConnectionTrackerService`
- Returns HTTP 429 when limit exceeded

### Authentication
- Username/password validation on every request
- User expiration date checking
- Active status verification

### Stream Protection
- All streams proxied through `/live/{username}/{password}/{stream_id}`
- Credentials validated on each stream request
- Stream URLs are hashed (MD5) to prevent direct access

## Troubleshooting

### "Invalid credentials" Error
- Verify username/password are correct
- Check user is active (`is_active = true`)
- Verify user hasn't expired (`expires_at`)

### "No M3U source assigned" Error
- Assign an M3U source to the user in admin panel
- Ensure the source is active

### "Maximum connections exceeded" Error
- User has reached their connection limit
- Check active connections in admin panel
- Increase `max_connections` for the user

### Empty Stream Lists
- Verify M3U source URL is accessible
- Check M3U source is active
- For file sources, ensure file exists in `storage/app/m3u_files/`
- Check Redis cache (1-hour TTL)

### Stream Won't Play
- Verify original stream URL is valid
- Check if `use_direct_urls` setting is appropriate
- Test stream URL directly

## Technical Details

### Caching
- Channel lists cached for 1 hour in Redis
- Cache key format: `m3u_channels_source_{source_id}`
- Clear cache by restarting Redis or waiting for TTL

### Stream ID Generation
- Stream IDs are MD5 hashes of stream URLs
- Format: `md5($channel['url'])`
- Ensures consistent IDs across requests

### Proxy Streaming
- Uses Laravel HTTP client with streaming
- Infinite timeout for live streams
- Content-Type: `video/mp2t`
- No caching headers

### Category Mapping
- Categories extracted from M3U `group-title` attribute
- Auto-incrementing category IDs
- Sorted alphabetically

## Limitations

1. **No VOD/Series Support**: Currently only live streams
2. **No EPG**: EPG endpoint returns empty array
3. **Plain Text Passwords**: User passwords stored without encryption
4. **Single Source Per User**: Each user can only access one M3U source
5. **No Catchup/Archive**: Time-shift not supported

## Future Enhancements

To add support for:
- VOD content (movies)
- Series (TV shows with episodes)
- EPG data integration
- Password hashing
- Multi-source per user
- Catchup/archive functionality

You would need to extend the `PlaylistController` methods that currently return empty arrays.

## Configuration Files

- **Controller**: `app/Http/Controllers/PlaylistController.php`
- **M3U Parser**: `app/Services/M3UParserService.php`
- **M3U Generator**: `app/Services/M3UGeneratorService.php`
- **Connection Tracker**: `app/Services/ConnectionTrackerService.php`
- **Routes**: `routes/web.php`
- **Environment**: `.env`

## Need Help?

For issues or questions:
1. Check application logs: `storage/logs/laravel.log`
2. Check Docker logs: `docker-compose logs -f app`
3. Verify database entries in `iptv_users` and `m3u_sources` tables
4. Test M3U source directly before assigning to users
