#!/bin/bash

echo "=========================================="
echo "Tuliprox Integration Diagnostic Script"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Find container names
echo "1. Finding container names..."
NOVATV_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i novatv | head -1)
TULIPROX_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i tuliprox | head -1)

if [ -z "$NOVATV_CONTAINER" ]; then
    echo -e "${RED}✗ Could not find novatv container${NC}"
    echo "Available containers:"
    docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"
    exit 1
else
    echo -e "${GREEN}✓ Found novatv container: $NOVATV_CONTAINER${NC}"
fi

if [ -z "$TULIPROX_CONTAINER" ]; then
    echo -e "${RED}✗ Could not find tuliprox container${NC}"
    echo "Available containers:"
    docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"
    exit 1
else
    echo -e "${GREEN}✓ Found tuliprox container: $TULIPROX_CONTAINER${NC}"
fi

echo ""

# Check networks
echo "2. Checking network connectivity..."
NOVATV_NETWORKS=$(docker inspect $NOVATV_CONTAINER --format='{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}')
TULIPROX_NETWORKS=$(docker inspect $TULIPROX_CONTAINER --format='{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}')

echo "   Novatv networks: $NOVATV_NETWORKS"
echo "   Tuliprox networks: $TULIPROX_NETWORKS"

# Find common network
COMMON_NETWORK=""
for net in $NOVATV_NETWORKS; do
    if echo "$TULIPROX_NETWORKS" | grep -q "$net"; then
        COMMON_NETWORK="$net"
        break
    fi
done

if [ -n "$COMMON_NETWORK" ]; then
    echo -e "${GREEN}✓ Both containers are on common network: $COMMON_NETWORK${NC}"
else
    echo -e "${RED}✗ No common network found!${NC}"
    echo -e "${YELLOW}Fix: Connect novatv to tuliprox network or vice versa${NC}"
fi

echo ""

# Get tuliprox IP
echo "3. Getting tuliprox IP address..."
TULIPROX_IP=$(docker inspect $TULIPROX_CONTAINER --format='{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' | head -1)
echo "   Tuliprox IP: $TULIPROX_IP"

echo ""

# Test DNS resolution from novatv
echo "4. Testing DNS resolution from novatv container..."
if docker exec $NOVATV_CONTAINER getent hosts tuliprox > /dev/null 2>&1; then
    echo -e "${GREEN}✓ DNS resolution works${NC}"
    RESOLVED_IP=$(docker exec $NOVATV_CONTAINER getent hosts tuliprox | awk '{print $1}')
    echo "   Resolved to: $RESOLVED_IP"
else
    echo -e "${RED}✗ Cannot resolve 'tuliprox' hostname${NC}"
    echo -e "${YELLOW}Fix: Add TULIPROX_HOST=$TULIPROX_IP to .env${NC}"
fi

echo ""

# Test HTTP connectivity
echo "5. Testing HTTP connectivity to tuliprox API..."
if docker exec $NOVATV_CONTAINER curl -s -f -m 5 "http://tuliprox:8901/api/v1/playlist/stats" > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Can connect to tuliprox API via hostname${NC}"
elif docker exec $NOVATV_CONTAINER curl -s -f -m 5 "http://$TULIPROX_IP:8901/api/v1/playlist/stats" > /dev/null 2>&1; then
    echo -e "${YELLOW}⚠ Can connect via IP but not hostname${NC}"
    echo -e "${YELLOW}Fix: Set TULIPROX_HOST=$TULIPROX_IP in .env${NC}"
else
    echo -e "${RED}✗ Cannot connect to tuliprox API${NC}"
    echo "Testing if tuliprox API is running..."
    if docker exec $TULIPROX_CONTAINER curl -s -f -m 5 "http://localhost:8901/api/v1/playlist/stats" > /dev/null 2>&1; then
        echo -e "${YELLOW}⚠ API is running but not accessible from novatv${NC}"
    else
        echo -e "${RED}✗ Tuliprox API is not responding${NC}"
    fi
fi

echo ""

# Check config directory permissions
echo "6. Checking /opt/tuliprox/config directory..."
if docker exec $NOVATV_CONTAINER test -d /opt/tuliprox/config; then
    echo -e "${GREEN}✓ Directory exists${NC}"

    # Check if writable
    if docker exec $NOVATV_CONTAINER test -w /opt/tuliprox/config; then
        echo -e "${GREEN}✓ Directory is writable${NC}"
    else
        echo -e "${RED}✗ Directory is NOT writable${NC}"
        echo -e "${YELLOW}Fix: Run 'docker exec $NOVATV_CONTAINER chmod -R 775 /opt/tuliprox/config'${NC}"
    fi

    # Show permissions
    PERMS=$(docker exec $NOVATV_CONTAINER ls -ld /opt/tuliprox/config)
    echo "   Permissions: $PERMS"
else
    echo -e "${RED}✗ Directory does not exist${NC}"
    echo -e "${YELLOW}Creating directory...${NC}"
    docker exec $NOVATV_CONTAINER mkdir -p /opt/tuliprox/config
    docker exec $NOVATV_CONTAINER chmod -R 775 /opt/tuliprox/config
    echo -e "${GREEN}✓ Directory created${NC}"
fi

echo ""

# Check existing YAML files
echo "7. Checking existing YAML files..."
if docker exec $NOVATV_CONTAINER test -f /opt/tuliprox/config/user.yml; then
    echo -e "${GREEN}✓ user.yml exists${NC}"
    USER_COUNT=$(docker exec $NOVATV_CONTAINER cat /opt/tuliprox/config/user.yml | grep -c "username:" || echo "0")
    echo "   Users configured: $USER_COUNT"
else
    echo -e "${YELLOW}⚠ user.yml does not exist yet${NC}"
fi

if docker exec $NOVATV_CONTAINER test -f /opt/tuliprox/config/source.yml; then
    echo -e "${GREEN}✓ source.yml exists${NC}"
    SOURCE_COUNT=$(docker exec $NOVATV_CONTAINER cat /opt/tuliprox/config/source.yml | grep -c "enabled:" || echo "0")
    echo "   Sources configured: $SOURCE_COUNT"
else
    echo -e "${YELLOW}⚠ source.yml does not exist yet${NC}"
fi

echo ""

# Summary and recommendations
echo "=========================================="
echo "Summary & Recommendations"
echo "=========================================="
echo ""

if [ -z "$COMMON_NETWORK" ]; then
    echo -e "${RED}CRITICAL:${NC} Containers are not on the same network"
    echo "Run: docker network connect $TULIPROX_NETWORKS $NOVATV_CONTAINER"
    echo ""
fi

if ! docker exec $NOVATV_CONTAINER getent hosts tuliprox > /dev/null 2>&1; then
    echo -e "${YELLOW}IMPORTANT:${NC} DNS resolution not working"
    echo "Add to your .env file:"
    echo "  TULIPROX_HOST=$TULIPROX_IP"
    echo ""
fi

if ! docker exec $NOVATV_CONTAINER test -w /opt/tuliprox/config 2>/dev/null; then
    echo -e "${RED}CRITICAL:${NC} Config directory not writable"
    echo "Run: docker exec $NOVATV_CONTAINER chmod -R 775 /opt/tuliprox/config"
    echo ""
fi

echo "To test the integration, run:"
echo "  docker exec $NOVATV_CONTAINER php artisan tuliprox:sync"
echo ""
