#!/bin/bash
# ==============================================================================
# Manual Rollback Script - Rollback from i18n to Official Coolify
# ==============================================================================
# Use this script to manually rollback from the i18n version to the official
# Coolify version.
#
# Usage:
#   sudo bash rollback-to-official.sh
# ==============================================================================

set -e

echo ""
echo "=========================================================="
echo "  Rolling Back from i18n to Official Coolify"
echo "=========================================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root or with sudo"
    exit 1
fi

# Check if Coolify is installed
if [ ! -d "/data/coolify/source" ]; then
    echo "ERROR: Coolify installation not found at /data/coolify/source"
    exit 1
fi

cd /data/coolify/source

# Get current image
CURRENT_IMAGE=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
echo "Current image: ${CURRENT_IMAGE}"
echo ""

# Check if currently using custom image
if [ ! -f docker-compose.custom.yml ]; then
    echo "No custom override found. You may already be on the official version."
    echo ""
    read -p "Continue anyway? (y/N): " CONFIRM
    if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
        echo "Rollback cancelled."
        exit 0
    fi
fi

# Confirm rollback
echo "This will:"
echo "  1. Remove docker-compose.custom.yml"
echo "  2. Restart Coolify with the official image"
echo "  3. NOT restore database (data will be preserved)"
echo ""
read -p "Proceed with rollback? (y/N): " CONFIRM

if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
    echo "Rollback cancelled."
    exit 0
fi

echo ""
echo "[1/3] Removing custom docker-compose override..."

if [ -f docker-compose.custom.yml ]; then
    # Backup before removing
    DATE=$(date +"%Y%m%d-%H%M%S")
    mkdir -p /data/coolify/backups
    cp docker-compose.custom.yml "/data/coolify/backups/docker-compose.custom-${DATE}.yml"
    echo "     Backed up to: /data/coolify/backups/docker-compose.custom-${DATE}.yml"

    rm -f docker-compose.custom.yml
    echo "     Removed docker-compose.custom.yml"
else
    echo "     No custom override found, skipping..."
fi

echo ""
echo "[2/3] Restarting Coolify with official image..."

docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  up -d --force-recreate coolify

echo ""
echo "[3/3] Waiting for Coolify to start..."
sleep 10

# Health check
HEALTH_TIMEOUT=60
HEALTH_WAITED=0
HEALTH_OK=false

while [ $HEALTH_WAITED -lt $HEALTH_TIMEOUT ]; do
    HEALTH=$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "unknown")

    if [ "$HEALTH" = "healthy" ]; then
        echo "     ✓ Coolify is healthy!"
        HEALTH_OK=true
        break
    fi

    if [ $((HEALTH_WAITED % 10)) -eq 0 ]; then
        echo "     Waiting... (${HEALTH_WAITED}s) - Status: ${HEALTH}"
    fi

    sleep 2
    HEALTH_WAITED=$((HEALTH_WAITED + 2))
done

echo ""
if [ "$HEALTH_OK" = true ]; then
    echo "=========================================================="
    echo "  ✓ Rollback Successful!"
    echo "=========================================================="
    echo ""
    echo "Current running image:"
    docker inspect coolify --format='{{.Config.Image}}'
    echo ""
    echo "Coolify is now running the official version."
else
    echo "=========================================================="
    echo "  ⚠ Rollback Completed with Warnings"
    echo "=========================================================="
    echo ""
    echo "Health status: ${HEALTH}"
    echo "Coolify may still be starting. Check logs:"
    echo "  docker logs coolify"
    echo ""
fi

echo "To switch back to i18n:"
echo "  sudo bash update-to-i18n.sh"
echo ""
