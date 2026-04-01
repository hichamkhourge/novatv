# Deployment Fix - 500 Error Resolution

## Issues Found & Fixed

### 1. Missing Model
- **Issue**: `ConnectionLog` model was missing
- **Fixed**: Created `app/Models/ConnectionLog.php`

### 2. Invalid Relationship Reference
- **Issue**: `IptvUser` model referenced deleted `StreamSession` model
- **Fixed**: Updated to use `ConnectionLog` instead

### 3. Non-Defensive Resource Column
- **Issue**: `IptvUserResource` tried to count relationship before migrations were run
- **Fixed**: Made sources count column defensive with try-catch

### 4. Scheduler Reference to Deleted Command
- **Issue**: Scheduler still referenced `iptv:cleanup-sessions` command
- **Fixed**: Removed from `bootstrap/app.php`

## Required Deployment Steps

### Step 1: Commit and Push Changes
```bash
git add .
git commit -m "Fix: Add missing ConnectionLog model, update IptvUser relationships, fix scheduler"
git push
```

### Step 2: Deploy via Dokploy
- Push will trigger automatic deployment
- Or manually redeploy in Dokploy UI

### Step 3: Run Migrations on Production
**IMPORTANT**: After deployment, you MUST run migrations on production:

```bash
# Via Dokploy exec or SSH into container
docker-compose exec app php artisan migrate

# Or if using Dokploy terminal
php artisan migrate
```

### Step 4: Restart Services
```bash
docker-compose restart app nginx queue scheduler
```

### Step 5: Clear Cache (Optional but Recommended)
```bash
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan route:clear
docker-compose exec app php artisan view:clear
```

## Verification

After completing the steps above, verify:

1. **Admin Panel Loads**: Visit https://novatv.novadevlabs.com/admin/iptv-users
2. **No 500 Errors**: Page should load without errors
3. **Scheduler Works**: Check logs for no namespace errors
4. **Database Tables Exist**: Verify new tables were created

### Check Database Tables
```bash
docker-compose exec app php artisan tinker
# Then run:
\DB::table('user_sources')->count();  // Should work (0 initially)
\DB::table('connection_logs')->count();  // Should work (0 initially)
\DB::table('channels')->first();  // Should return null or data
```

## Migration Summary

The following migrations need to be applied:
1. `refactor_m3u_sources_table_for_new_system` - Updates m3u_sources
2. `drop_packages_and_channel_groups_tables` - Removes old tables
3. `recreate_channels_table_with_new_schema` - Recreates channels
4. `refactor_iptv_users_table_remove_direct_relationships` - Updates iptv_users
5. `create_user_sources_pivot_table` - Creates pivot table
6. `create_connection_logs_table` - Creates connection logs
7. `drop_tuliprox_related_tables` - Removes tuliprox tables

## Expected Result

After migrations:
- ✅ IPTV Users page loads successfully
- ✅ Can create/edit IPTV users
- ✅ Can link M3U sources to users
- ✅ Scheduler runs without errors
- ✅ All Filament resources work

## Rollback Plan (if needed)

If something goes wrong:
```bash
# Rollback last batch of migrations
php artisan migrate:rollback

# Or rollback specific steps
php artisan migrate:rollback --step=7
```

## Notes

- The old `packages`, `channel_groups`, and `tuliprox_servers` tables will be dropped
- Existing `iptv_users` data should be preserved
- Old `channels` table data will be lost (will be repopulated by M3U sync)
- If you need to preserve channel data, backup before running migrations
