# Quick Start: Using Your M3U File with Xtream API

## What You Need

You mentioned you have:
- An M3U file/link from an external server
- Want users to connect via Xtream Codes API format
- Users connect to **your server** (not the external one)

## Good News! ✅

Your application **already supports this!** The Xtream API is fully functional.

## Setup Steps

### 1. Add Your M3U Source

**Via Admin Panel (Filament):**

1. Navigate to **M3U Sources** → **Create**
2. Fill in:
   - **Name**: "My IPTV Source" (or any name)
   - **Source Type**:
     - Choose **"URL"** if you have a link to the M3U file
     - Choose **"File"** if you want to upload the M3U file directly
   - **URL** (if using URL): Paste your M3U link
   - **File** (if using File): Upload your M3U file
   - **Use Direct URLs**: Leave unchecked to proxy streams through your server
   - **Is Active**: Check this box
3. Click **Save**

### 2. Create IPTV Users

1. Go to **IPTV Users** → **Create**
2. Fill in:
   - **Username**: e.g., "john123"
   - **Password**: e.g., "securepass456"
   - **M3U Source**: Select the source you created in step 1
   - **Max Connections**: 1 (or more if you want to allow multiple devices)
   - **Is Active**: Check this box
   - **Expires At**: Optional - set when subscription should expire
3. Click **Save**

### 3. Give Users Their Connection Info

Your users connect with:

```
Server/Host: https://novatv.novadevlabs.com
Username: [their username from step 2]
Password: [their password from step 2]
```

They can use any Xtream-compatible app:
- TiviMate
- IPTV Smarters Pro
- GSE Smart IPTV
- Perfect Player
- VLC
- etc.

## Example Configuration in TiviMate

1. Open TiviMate
2. Select **"Add Playlist"**
3. Choose **"Xtream Codes API"**
4. Enter:
   ```
   Server: https://novatv.novadevlabs.com
   Username: john123
   Password: securepass456
   ```
5. Click **"Next"** and load channels

## Testing the Setup

Test with cURL to verify it's working:

```bash
# Get user info
curl "https://novatv.novadevlabs.com/player_api.php?username=john123&password=securepass456"

# Get categories
curl "https://novatv.novadevlabs.com/player_api.php?username=john123&password=securepass456&action=get_live_categories"

# Get streams
curl "https://novatv.novadevlabs.com/player_api.php?username=john123&password=securepass456&action=get_live_streams"
```

## What Was Enhanced

I've added missing Xtream API endpoints to make your implementation complete:

**New endpoints added:**
- `action=get_vod_categories` (currently returns empty - for future VOD support)
- `action=get_vod_streams` (currently returns empty - for future VOD support)
- `action=get_series_categories` (currently returns empty - for future series support)
- `action=get_series` (currently returns empty - for future series support)
- `action=get_short_epg` (currently returns empty - for future EPG support)

**Already working endpoints:**
- User authentication
- `get_live_categories` - Returns channel groups from your M3U
- `get_live_streams` - Returns all channels
- Stream playback via `/live/{username}/{password}/{stream_id}.ts`
- M3U playlist download via `/get.php`

## File Locations

- **Controller**: `app/Http/Controllers/PlaylistController.php`
- **Full Documentation**: `XTREAM_API_GUIDE.md`
- **This Guide**: `SETUP_QUICK_START.md`

## Important Notes

1. **Streams are proxied** through your server by default (gives you control and tracking)
2. **Connection limits** are enforced per user
3. **Passwords are stored in plain text** in the database (consider hashing in production)
4. **Each user can access ONE M3U source** (the one assigned to them)

## Need More Details?

See the full **XTREAM_API_GUIDE.md** for:
- Complete API documentation
- All endpoint details
- Troubleshooting guide
- Security features
- Technical implementation details

## Common Questions

**Q: Can I use my own domain instead of novatv.novadevlabs.com?**
A: Yes, change `APP_URL` and `DOMAIN` in your `.env` file.

**Q: Can users watch on multiple devices?**
A: Yes, increase their `max_connections` value.

**Q: How do I add more M3U sources?**
A: Just create more sources in the admin panel and assign different sources to different users.

**Q: My users can't connect - what's wrong?**
A: Check:
1. User is active (`is_active = true`)
2. User hasn't expired (`expires_at`)
3. M3U source is assigned to the user
4. M3U source is active
5. Username/password are correct

## You're All Set! 🎉

Your Xtream API is ready to use. Just:
1. Upload/add your M3U source
2. Create users
3. Give them the connection details
4. They can start watching!
