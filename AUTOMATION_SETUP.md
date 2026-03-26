# IPTV Provider Automation System - Setup Guide

## Overview

This system allows automated renewal of user subscriptions across different IPTV providers (UGEEN, ZAZY, etc.). Each source can have provider-specific automation scripts that run daily to renew subscriptions for assigned users.

## What Was Implemented

### 1. Database Changes

**New Fields in `m3u_sources` Table:**
- `provider_type` - Type of provider (ugeen, zazy, custom, none)
- `provider_username` - Encrypted provider login username/email
- `provider_password` - Encrypted provider login password
- `provider_config` - JSON configuration (package_id, etc.)
- `script_path` - Path to custom Python script
- `automation_enabled` - Enable/disable automation
- `last_automation_run` - Timestamp of last run
- `automation_status` - Status message from last run

**New Table: `user_automation_logs`**
- `iptv_user_id` - Which user was processed
- `m3u_source_id` - Which source/provider
- `status` - pending, running, success, failed
- `output` - Script stdout
- `error` - Script stderr
- `duration_seconds` - How long it took
- `started_at` / `completed_at` - Timestamps

### 2. Python Script

**Location:** `scripts/ugeen_renew_user.py`

**Features:**
- Accepts CLI arguments instead of hardcoded credentials
- Uses environment variables for sensitive data
- Session caching to avoid repeated logins
- Comprehensive logging
- Exit codes for success/failure

**Usage:**
```bash
python3 scripts/ugeen_renew_user.py \
  --user-id 123 \
  --provider-username "email@example.com" \
  --provider-password "password123" \
  --package-id "384"
```

### 3. Laravel Integration

**Job:** `App\Jobs\RenewUserSubscriptionJob`
- Handles per-user renewal
- Decrypts provider credentials
- Executes Python script
- Logs output and errors
- Updates automation status

**Command:** `php artisan iptv:run-user-renewals`
- Finds all sources with automation enabled
- Finds active users for each source
- Dispatches renewal jobs to queue
- Supports --source and --user filters for testing

**Scheduler:** Runs daily at 2 AM
```php
$schedule->command('iptv:run-user-renewals')->dailyAt('02:00');
```

### 4. Admin Panel (Filament)

**M3U Source Form - New Section:** "Automation Configuration"
- Provider Type dropdown
- Provider credentials (encrypted)
- Additional configuration (JSON key-value)
- Custom script path
- Enable/Disable automation toggle
- Last run status display

**Table Columns Added:**
- Provider badge (color-coded)
- Auto Renewal status
- Last Run timestamp

## Deployment Instructions

### Step 1: Rebuild Docker Containers

The Dockerfile has been updated to include Python 3, Chromium, and dependencies.

```bash
# Stop containers
docker-compose down

# Rebuild images
docker-compose build

# Start containers
docker-compose up -d

# Check logs
docker-compose logs -f app
```

### Step 2: Run Migrations

```bash
docker-compose exec app php artisan migrate --force
```

This will create:
- New automation fields in `m3u_sources`
- New `user_automation_logs` table

### Step 3: Install Python Dependencies

The dependencies are installed during Docker build, but if you need to manually install:

```bash
docker-compose exec app pip3 install -r requirements.txt
```

### Step 4: Configure Environment Variables

Already added to `.env`:
```env
TWOCAPTCHA_API_KEY=3d8b2544d243bf3b8057fe912a37b970
UGEEN_HEADLESS=true
SESSION_DIR=/tmp/iptv_sessions
```

**Security Note:** Move the 2captcha API key to a secure location in production!

### Step 5: Configure Sources

1. Log into admin panel (Filament)
2. Go to **M3U Sources**
3. Edit your UGEEN source
4. Scroll to **Automation Configuration** section
5. Fill in:
   - **Provider Type:** UGEEN
   - **Provider Username:** Your UGEEN email
   - **Provider Password:** Your UGEEN password
   - **Additional Configuration:** `{"package_id": "384"}` (or your package)
   - **Enable Automation:** Toggle ON
6. Save

Repeat for ZAZY or other providers.

### Step 6: Test the Automation

**Test for a specific user:**
```bash
docker-compose exec app php artisan iptv:run-user-renewals --user=1
```

**Test for a specific source:**
```bash
docker-compose exec app php artisan iptv:run-user-renewals --source=1
```

**Run for all:**
```bash
docker-compose exec app php artisan iptv:run-user-renewals
```

### Step 7: Check Logs

**Laravel logs:**
```bash
docker-compose exec app tail -f storage/logs/laravel.log
```

**Queue worker logs:**
```bash
docker-compose logs -f queue
```

**Check automation logs in database:**
```sql
SELECT * FROM user_automation_logs ORDER BY created_at DESC LIMIT 10;
```

## How It Works

### Daily Workflow

```
2:00 AM (Daily)
  ↓
Scheduler runs: iptv:run-user-renewals
  ↓
Command finds sources with automation_enabled = true
  ↓
For each source:
  ↓
  Find active users assigned to this source
    ↓
    For each user:
      ↓
      Dispatch RenewUserSubscriptionJob to queue
        ↓
        Job decrypts provider credentials
        ↓
        Job executes Python script with user-specific args
        ↓
        Script logs in to provider (UGEEN/ZAZY)
        ↓
        Script requests renewal code
        ↓
        Script submits renewal form
        ↓
        Script returns success/failure
        ↓
        Job logs output to user_automation_logs
        ↓
        Job updates source.last_automation_run
        ↓
        Job updates source.automation_status
```

### Per-User Execution

Each user gets their subscription renewed individually:
- Uses source provider credentials
- Runs script with user ID
- Logs are tracked per user
- Failures don't affect other users

## Configuration Options

### Provider Config (JSON)

**UGEEN:**
```json
{
  "package_id": "384"
}
```

**ZAZY (when implemented):**
```json
{
  "package_id": "your_package_id",
  "api_key": "optional_api_key"
}
```

**Custom:**
```json
{
  "any_key": "any_value",
  "another_key": "another_value"
}
```

### Script Path

**Auto-detected paths:**
- UGEEN: `{base_path}/scripts/ugeen_renew_user.py`
- ZAZY: `{base_path}/scripts/zazy_renew_user.py`

**Custom path example:**
```
/var/www/html/scripts/my_custom_provider.py
```

## Creating Scripts for New Providers

### Template

```python
#!/usr/bin/env python3
import argparse
import sys

def renew_subscription(user_id, provider_username, provider_password, **config):
    """
    Main renewal logic here
    """
    try:
        # Your automation code
        print(f"Renewing subscription for user {user_id}")

        # On success
        return True
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return False

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--user-id', required=True)
    parser.add_argument('--provider-username', required=True)
    parser.add_argument('--provider-password', required=True)
    parser.add_argument('--package-id', default='')

    args = parser.parse_args()

    success = renew_subscription(
        user_id=args.user_id,
        provider_username=args.provider_username,
        provider_password=args.provider_password,
        package_id=args.package_id
    )

    sys.exit(0 if success else 1)

if __name__ == '__main__':
    main()
```

### Requirements

1. Accept CLI arguments for credentials
2. Exit with code 0 on success, 1 on failure
3. Print progress to stdout
4. Print errors to stderr
5. Use environment variables for API keys
6. Implement session caching if possible

## Monitoring

### Check Automation Status

**In Admin Panel:**
1. Go to M3U Sources
2. Look at "Auto Renewal" column
3. Click Edit on a source
4. Check "Last Automation Run" and "Last Run Status"

**Via Database:**
```sql
-- Recent automation runs
SELECT
    u.username,
    s.name as source_name,
    l.status,
    l.duration_seconds,
    l.completed_at,
    l.error
FROM user_automation_logs l
JOIN iptv_users u ON l.iptv_user_id = u.id
JOIN m3u_sources s ON l.m3u_source_id = s.id
ORDER BY l.created_at DESC
LIMIT 20;

-- Failed renewals
SELECT * FROM user_automation_logs
WHERE status = 'failed'
ORDER BY created_at DESC;

-- Sources with automation
SELECT
    name,
    provider_type,
    automation_enabled,
    last_automation_run,
    automation_status
FROM m3u_sources
WHERE provider_type != 'none';
```

## Troubleshooting

### Script Fails to Run

**Check Python is installed:**
```bash
docker-compose exec app python3 --version
```

**Check script exists:**
```bash
docker-compose exec app ls -la scripts/
```

**Check script permissions:**
```bash
docker-compose exec app chmod +x scripts/*.py
```

### Credentials Not Working

**Check encryption:**
```bash
docker-compose exec app php artisan tinker
>>> $source = \App\Models\M3uSource::find(1);
>>> \Illuminate\Support\Facades\Crypt::decryptString($source->provider_username);
```

### Queue Not Processing

**Check queue worker is running:**
```bash
docker-compose ps queue
```

**Manually process queue:**
```bash
docker-compose exec app php artisan queue:work --once
```

### Chromium Issues

**Check Chromium is installed:**
```bash
docker-compose exec app chromium-browser --version
```

**Run script with visible browser (for debugging):**
Edit `.env`:
```
UGEEN_HEADLESS=false
```

Then rebuild containers.

## Security Considerations

### Important!

1. **2captcha API Key:** Currently in `.env` - move to secrets manager in production
2. **Provider Passwords:** Encrypted in database using Laravel's encryption
3. **Session Files:** Stored in `/tmp/iptv_sessions` - ensure proper permissions
4. **Script Output:** May contain sensitive data - review logs regularly

### Recommendations

- Use environment-specific `.env` files
- Rotate 2captcha API key regularly
- Monitor failed login attempts
- Set up alerts for automation failures
- Review automation logs weekly
- Implement rate limiting if needed

## Next Steps

### For ZAZY Provider

1. Copy `scripts/ugeen_renew_user.py` to `scripts/zazy_renew_user.py`
2. Modify authentication flow for ZAZY
3. Update login URL and form selectors
4. Test with a single user
5. Enable automation in source config

### For Additional Providers

1. Create `scripts/{provider}_renew_user.py`
2. Add provider type to enum in migration
3. Update Filament form options
4. Follow template above
5. Test thoroughly

## Files Modified/Created

### New Files
- `database/migrations/2026_03_25_110834_add_automation_fields_to_m3u_sources_table.php`
- `database/migrations/2026_03_25_110900_create_user_automation_logs_table.php`
- `app/Models/UserAutomationLog.php`
- `app/Jobs/RenewUserSubscriptionJob.php`
- `app/Console/Commands/RunUserRenewalsCommand.php`
- `scripts/ugeen_renew_user.py`
- `requirements.txt`
- `AUTOMATION_SETUP.md` (this file)

### Modified Files
- `app/Models/M3uSource.php` - Added fillable fields and casts
- `app/Filament/Resources/M3uSourceResource.php` - Added automation form section
- `bootstrap/app.php` - Added daily scheduler
- `Dockerfile` - Added Python, Chromium, dependencies
- `.env` - Added automation config

## Support

For issues or questions:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Check automation logs table
3. Check Docker container logs
4. Review this documentation

Good luck! 🚀
