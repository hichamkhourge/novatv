# Production Deployment Steps

## Current Status
✅ Code deployed
✅ YAML generation fixed (showing `[]` instead of `{}`)
❌ Migrations not run
❌ No TuliproxServer records

## Steps to Complete

### 1. Run Migrations in Production

```bash
docker exec iptvprovider_app_1 php artisan migrate --force
```

**Expected output:**
```
Migrating: 2026_03_27_225231_create_channels_table
Migrated:  2026_03_27_225231_create_channels_table
Migrating: 2026_03_27_225302_create_iptv_user_channel_table
Migrated:  2026_03_27_225302_create_iptv_user_channel_table
Migrating: 2026_03_27_225330_create_tuliprox_servers_table
Migrated:  2026_03_27_225330_create_tuliprox_servers_table
Migrating: 2026_03_27_225358_add_target_name_to_m3u_sources_table
Migrated:  2026_03_27_225358_add_target_name_to_m3u_sources_table
Migrating: 2026_03_27_225420_add_tuliprox_server_id_to_iptv_users_table
Migrated:  2026_03_27_225420_add_tuliprox_server_id_to_iptv_users_table
```

### 2. Create TuliproxServer Records

```bash
docker exec -it iptvprovider_app_1 php artisan tinker
```

Inside tinker, run:

```php
use App\Models\TuliproxServer;

// Create external server (production)
TuliproxServer::create([
    'name' => 'external',
    'protocol' => 'https',
    'host' => 'tuliprox.novadevlabs.com',
    'port' => '443',
    'timezone' => 'Africa/Casablanca',
    'message' => 'Welcome to Tuliprox',
    'is_default' => true,
    'is_active' => true,
]);

// Create local server (optional)
TuliproxServer::create([
    'name' => 'local',
    'protocol' => 'http',
    'host' => '172.24.0.8',
    'port' => '8991',
    'timezone' => 'Africa/Casablanca',
    'message' => 'Welcome to Tuliprox',
    'is_default' => false,
    'is_active' => true,
]);

// Verify
TuliproxServer::count(); // Should return 2
exit;
```

### 3. Update M3U Sources with Target Names

```bash
docker exec -it iptvprovider_app_1 php artisan tinker
```

Inside tinker:

```php
use App\Models\M3uSource;

// Update your existing M3U source
$source = M3uSource::first(); // Or find by ID
$source->update(['target_name' => 'ugeenkhourge']); // Use lowercase, no spaces

// Verify
M3uSource::whereNotNull('target_name')->count();
exit;
```

### 4. Fetch Channels from M3U Source

```bash
docker exec iptvprovider_app_1 php artisan m3u:fetch 1
```

Or fetch all sources:

```bash
docker exec iptvprovider_app_1 php artisan m3u:fetch --all
```

**Expected output:**
```
Fetching channels from: Ugeen Khourge
URL: http://ugeen.live:8080/get.php?...

✓ Fetch completed successfully!

+------------------+-------+
| Metric           | Count |
+------------------+-------+
| Total Channels   | 920   |
| Added            | 920   |
| Updated          | 0     |
| Removed          | 0     |
+------------------+-------+

Channel Groups (15):
  - Sports
  - Movies
  - News
  ...
```

### 5. Assign Channels to Users

```bash
docker exec -it iptvprovider_app_1 php artisan tinker
```

Inside tinker:

```php
use App\Models\IptvUser;
use App\Models\Channel;

$user = IptvUser::where('username', 'Yanis')->first();

// Option A: Assign all channels from their M3U source
$channels = Channel::where('m3u_source_id', $user->m3u_source_id)
    ->where('is_active', true)
    ->get();

$user->channels()->sync($channels->pluck('id'));

// Option B: Assign only specific groups
$channels = Channel::where('m3u_source_id', $user->m3u_source_id)
    ->whereIn('group_name', ['Sports', 'Movies'])
    ->where('is_active', true)
    ->get();

$user->channels()->sync($channels->pluck('id'));

// Verify
echo $user->channels()->count() . " channels assigned\n";
exit;
```

### 6. Sync to Tuliprox

```bash
docker exec iptvprovider_app_1 php artisan tuliprox:sync
```

**Expected output:**
```
Starting tuliprox synchronization...

Syncing all configuration files...

Syncing source.yml...
  ✓ Successfully synced 1 active source(s) to source.yml

Syncing user.yml...
  ✓ Successfully synced 1 active user(s) to user.yml

Syncing api-proxy.yml...
  ✓ Successfully synced api-proxy.yml

Tuliprox synchronization completed successfully!
```

### 7. Verify YAML Files

```bash
cat /opt/tuliprox/config/api-proxy.yml
```

**Expected output:**
```yaml
server:
  - name: external
    protocol: https
    host: tuliprox.novadevlabs.com
    port: '443'
    timezone: Africa/Casablanca
    message: Welcome to Tuliprox
user:
  - target: ugeenkhourge
    credentials:
      - username: Yanis
        password: yanis
        token: Yanis
        proxy: reverse
        server: external
        exp_date: 1735689600
        max_connections: 1
        status: Active
        filter: 'Group ~ "^(Sports|Movies)$"'
        ui_enabled: true
```

```bash
cat /opt/tuliprox/config/source.yml
```

```bash
cat /opt/tuliprox/config/user.yml
```

### 8. Test with Tuliprox

```bash
docker logs tuliprox --tail 50
```

---

## Troubleshooting

### If migrations fail

```bash
# Check if tables already exist
docker exec iptvprovider_app_1 php artisan tinker --execute="echo \DB::table('channels')->count();"
```

### If sync shows errors

```bash
# Check logs
docker exec iptvprovider_app_1 tail -f storage/logs/laravel.log | grep Tuliprox
```

### If no channels fetched

```bash
# Test M3U URL manually
curl -I "http://ugeen.live:8080/get.php?username=xxx&password=xxx&type=m3u_plus&output=ts"
```

---

## Summary

**Why arrays are empty:**
- ✅ YAML generation is working (shows `[]` not `{}`)
- ❌ No TuliproxServer records in database yet
- ❌ No channels fetched yet
- ❌ No channel assignments yet

**After completing all steps above:**
- api-proxy.yml will have servers and user credentials
- source.yml will have M3U sources
- user.yml will have user-to-target mappings
- Per-user channel filtering will work
