#!/bin/bash
# Quick script to check if a user exists in iptv_users table

USER_ID=$1

if [ -z "$USER_ID" ]; then
    echo "Usage: ./check_user.sh <user_id>"
    exit 1
fi

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Load environment variables from .env file
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(cat "$PROJECT_ROOT/.env" | grep -v '^#' | grep -v '^$' | xargs)
fi

echo "Checking for user ID: $USER_ID in iptv_users table..."
echo ""

# Run SQL query
PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -p $DB_PORT -U $DB_USERNAME -d $DB_DATABASE -c "
SELECT
    u.id,
    u.username,
    u.m3u_source_id,
    s.name as source_name,
    s.provider_type
FROM iptv_users u
LEFT JOIN m3u_sources s ON u.m3u_source_id = s.id
WHERE u.id = $USER_ID;
"

echo ""
echo "All users in iptv_users table:"
PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -p $DB_PORT -U $DB_USERNAME -d $DB_DATABASE -c "
SELECT
    u.id,
    u.username,
    u.m3u_source_id,
    s.name as source_name,
    s.provider_type
FROM iptv_users u
LEFT JOIN m3u_sources s ON u.m3u_source_id = s.id
ORDER BY u.id;
"
