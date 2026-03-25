#!/bin/bash

# Nginx Reverse Proxy Test Script
# This script tests the fixed nginx reverse proxy configuration

echo "=================================="
echo "Nginx Reverse Proxy Test Script"
echo "=================================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Test nginx syntax
echo "Step 1: Testing nginx configuration syntax..."
if docker-compose exec nginx nginx -t 2>&1 | grep -q "test is successful"; then
    echo -e "${GREEN}✓ Nginx syntax is valid${NC}"
else
    echo -e "${RED}✗ Nginx syntax test failed${NC}"
    echo "Fix nginx configuration errors before continuing"
    exit 1
fi
echo ""

# Step 2: Test PHP-FPM is running
echo "Step 2: Checking PHP-FPM status..."
if docker-compose ps app | grep -q "Up"; then
    echo -e "${GREEN}✓ PHP-FPM is running${NC}"
else
    echo -e "${RED}✗ PHP-FPM is not running${NC}"
    echo "Start PHP-FPM: docker-compose up -d app"
    exit 1
fi
echo ""

# Step 3: Test auth endpoint
echo "Step 3: Testing authentication endpoint..."
echo -e "${YELLOW}Note: Using default credentials (hicham/hicham). Adjust if different.${NC}"
echo ""

AUTH_RESPONSE=$(docker-compose exec -T nginx curl -s -w "\n%{http_code}" "http://localhost/api/auth/stream" \
  -H "X-Stream-Username: hicham" \
  -H "X-Stream-Password: hicham" \
  -H "X-Stream-Id: test_stream_id" 2>/dev/null)

HTTP_CODE=$(echo "$AUTH_RESPONSE" | tail -n1)
HEADERS=$(echo "$AUTH_RESPONSE" | grep -i "x-upstream")

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
    if echo "$AUTH_RESPONSE" | grep -qi "x-upstream-scheme"; then
        echo -e "${GREEN}✓ Auth endpoint responding correctly${NC}"
        echo "  Headers returned:"
        echo "$AUTH_RESPONSE" | grep -i "x-upstream" | sed 's/^/    /'
    elif [ "$HTTP_CODE" = "404" ]; then
        echo -e "${YELLOW}⚠ Auth endpoint works but stream not found (this is OK for test ID)${NC}"
        echo "  This means authentication is working!"
    else
        echo -e "${RED}✗ Auth endpoint not returning URL components${NC}"
        echo "  Response: $AUTH_RESPONSE"
    fi
elif [ "$HTTP_CODE" = "403" ]; then
    echo -e "${RED}✗ Authentication failed (invalid credentials)${NC}"
    echo "  Check username/password are correct"
    echo "  Verify user exists: php artisan tinker -> IptvUser::where('username', 'hicham')->first()"
elif [ "$HTTP_CODE" = "500" ]; then
    echo -e "${RED}✗ Server error${NC}"
    echo "  Check Laravel logs: docker-compose logs app | tail -50"
else
    echo -e "${RED}✗ Unexpected response: HTTP $HTTP_CODE${NC}"
    echo "  Response: $AUTH_RESPONSE"
fi
echo ""

# Step 4: Test getting a real stream ID
echo "Step 4: Getting a real stream ID from database..."
echo -e "${YELLOW}This requires PHP artisan. Run manually:${NC}"
echo ""
echo "php artisan tinker << 'EOF'"
echo "\$parser = app(\App\Services\M3UParserService::class);"
echo "\$channels = \$parser->getChannelsBySource(1);"
echo "if (count(\$channels) > 0) {"
echo "    \$streamId = md5(\$channels[0]['url']);"
echo "    echo \"Stream ID: \$streamId\\n\";"
echo "    echo \"Stream URL: \" . \$channels[0]['url'] . \"\\n\";"
echo "    echo \"Test with: curl -I 'http://localhost/live/hicham/hicham/\$streamId.ts'\\n\";"
echo "} else {"
echo "    echo \"No channels found. Check M3U source.\\n\";"
echo "}"
echo "exit"
echo "EOF"
echo ""

# Step 5: Monitor logs
echo "Step 5: To monitor logs in real-time, run these commands in separate terminals:"
echo ""
echo -e "${YELLOW}Terminal 1 - Laravel auth logs:${NC}"
echo "docker-compose logs -f app | grep 'Stream auth'"
echo ""
echo -e "${YELLOW}Terminal 2 - Nginx debug logs:${NC}"
echo "docker-compose exec nginx tail -f /var/log/nginx/stream_debug.log"
echo ""
echo -e "${YELLOW}Terminal 3 - Nginx error logs:${NC}"
echo "docker-compose exec nginx tail -f /var/log/nginx/stream_error.log"
echo ""

# Summary
echo "=================================="
echo "Summary"
echo "=================================="
echo ""
echo "1. Nginx configuration: $(docker-compose exec nginx nginx -t 2>&1 | grep -q "test is successful" && echo -e "${GREEN}✓ Valid${NC}" || echo -e "${RED}✗ Invalid${NC}")"
echo "2. PHP-FPM status: $(docker-compose ps app | grep -q "Up" && echo -e "${GREEN}✓ Running${NC}" || echo -e "${RED}✗ Not running${NC}")"
echo "3. Auth endpoint: HTTP $HTTP_CODE"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Get a real stream ID (see Step 4 above)"
echo "2. Test with: curl -I 'http://localhost/live/hicham/hicham/STREAM_ID.ts'"
echo "3. Test in VLC: http://your-domain.com/live/hicham/hicham/STREAM_ID.ts"
echo "4. Test in iboplayer with Xtream Codes API"
echo ""
echo "See NGINX_PROXY_FIX_GUIDE.md for detailed troubleshooting."
echo ""
