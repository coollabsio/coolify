#!/bin/bash
# ==============================================================================
# Safe Update Script for Coolify i18n (with Auto-Rollback)
# ==============================================================================
# This script safely switches Coolify to the i18n version with automatic
# rollback on failure.
#
# Features:
#   - Automatic backup of current state
#   - Health check after update
#   - Auto-rollback if update fails
#   - Manual rollback script generation
#
# Usage:
#   sudo bash update-to-i18n.sh
# ==============================================================================

set -e

DATE=$(date +"%Y%m%d-%H%M%S")
BACKUP_DIR="/data/coolify/backups/update-${DATE}"
ROLLBACK_SCRIPT="/data/coolify/rollback-from-i18n.sh"

echo ""
echo "=========================================================="
echo "  Safely Updating Coolify to i18n Version"
echo "=========================================================="
echo ""
echo "Target Image: docker.io/a3180623/coolify:i18n"
echo "Backup Directory: ${BACKUP_DIR}"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root or with sudo"
    exit 1
fi

# Check if Coolify is installed
if [ ! -d "/data/coolify/source" ]; then
    echo "ERROR: Coolify installation not found at /data/coolify/source"
    echo "Please install Coolify first"
    exit 1
fi

cd /data/coolify/source

# ============================================================
# Step 1: Backup current state
# ============================================================
echo "[1/6] Creating backup of current state..."

mkdir -p "${BACKUP_DIR}"

# Backup current image info
CURRENT_IMAGE=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
echo "${CURRENT_IMAGE}" > "${BACKUP_DIR}/original-image.txt"
echo "     Current image: ${CURRENT_IMAGE}"

# Backup .env file
if [ -f .env ]; then
    cp .env "${BACKUP_DIR}/.env.backup"
    echo "     Backed up .env"
fi

# Backup existing custom compose (if exists)
if [ -f docker-compose.custom.yml ]; then
    cp docker-compose.custom.yml "${BACKUP_DIR}/docker-compose.custom.yml.backup"
    echo "     Backed up existing docker-compose.custom.yml"
fi

# Backup database
echo "     Backing up database..."
docker exec coolify-db pg_dump -U ${DB_USERNAME:-coolify} ${DB_DATABASE:-coolify} > "${BACKUP_DIR}/database-backup.sql" 2>/dev/null || {
    echo "     WARNING: Database backup failed, but continuing..."
}

echo "     Backup completed: ${BACKUP_DIR}"

# ============================================================
# Step 2: Create rollback script
# ============================================================
echo ""
echo "[2/6] Creating rollback script..."

cat > "${ROLLBACK_SCRIPT}" <<EOF
#!/bin/bash
# Auto-generated rollback script
# Created: ${DATE}
# Original image: ${CURRENT_IMAGE}

echo "=========================================================="
echo "  Rolling back from i18n to original Coolify"
echo "=========================================================="
echo ""
echo "Original image: ${CURRENT_IMAGE}"
echo ""

cd /data/coolify/source

# Remove custom override
if [ -f docker-compose.custom.yml ]; then
    echo "Removing custom docker-compose override..."
    rm -f docker-compose.custom.yml
fi

# Restore original image
echo "Restarting Coolify with original image..."
docker compose --env-file .env \\
  -f docker-compose.yml \\
  -f docker-compose.prod.yml \\
  up -d --force-recreate coolify

echo ""
echo "Waiting for Coolify to start..."
sleep 10

HEALTH=\$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "unknown")
echo "Health status: \${HEALTH}"

if [ "\${HEALTH}" = "healthy" ]; then
    echo "✓ Rollback successful! Coolify is running with original image."
else
    echo "⚠ Coolify is running but health check failed. Check logs: docker logs coolify"
fi

echo ""
echo "Current image:"
docker inspect coolify --format='{{.Config.Image}}'
echo ""
echo "To restore database from backup:"
echo "  cat ${BACKUP_DIR}/database-backup.sql | docker exec -i coolify-db psql -U \${DB_USERNAME:-coolify} \${DB_DATABASE:-coolify}"
echo ""
EOF

chmod +x "${ROLLBACK_SCRIPT}"
echo "     Rollback script created: ${ROLLBACK_SCRIPT}"

# ============================================================
# Step 3: Pull i18n image
# ============================================================
echo ""
echo "[3/6] Pulling docker.io/a3180623/coolify:i18n..."

# Pull image first to catch errors before stopping service
docker pull docker.io/a3180623/coolify:i18n || {
    echo ""
    echo "ERROR: Failed to pull i18n image from Docker Hub"
    echo "Please check:"
    echo "  1. Image exists: docker.io/a3180623/coolify:i18n"
    echo "  2. Network connectivity"
    echo "  3. Docker Hub is accessible"
    exit 1
}

echo "     Image pulled successfully!"

# ============================================================
# Step 4: Create custom override
# ============================================================
echo ""
echo "[4/6] Creating custom docker-compose override..."

cat > docker-compose.custom.yml <<'EOF'
# Custom override to use a3180623/coolify:i18n image
# Created by update-to-i18n.sh
services:
  coolify:
    image: "docker.io/a3180623/coolify:i18n"
EOF

echo "     Custom compose file created!"

# ============================================================
# Step 5: Update Coolify
# ============================================================
echo ""
echo "[5/6] Updating Coolify to i18n version..."

docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.custom.yml \
  up -d --force-recreate coolify

# ============================================================
# Step 6: Health check with auto-rollback
# ============================================================
echo ""
echo "[6/6] Performing health check (timeout: 60s)..."

HEALTH_TIMEOUT=60
HEALTH_WAITED=0
HEALTH_OK=false

while [ $HEALTH_WAITED -lt $HEALTH_TIMEOUT ]; do
    HEALTH=$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "unknown")

    if [ "$HEALTH" = "healthy" ]; then
        echo "     ✓ Coolify is healthy!"
        HEALTH_OK=true
        break
    elif [ "$HEALTH" = "unhealthy" ]; then
        echo "     ✗ Health check failed!"
        break
    fi

    if [ $((HEALTH_WAITED % 10)) -eq 0 ]; then
        echo "     Waiting... (${HEALTH_WAITED}s) - Status: ${HEALTH}"
    fi

    sleep 2
    HEALTH_WAITED=$((HEALTH_WAITED + 2))
done

# Check if update succeeded
if [ "$HEALTH_OK" = true ]; then
    echo ""
    echo "=========================================================="
    echo "  ✓ Update Successful!"
    echo "=========================================================="
    echo ""
    echo "Current running image:"
    docker inspect coolify --format='{{.Config.Image}}'
    echo ""
    echo "Backup location: ${BACKUP_DIR}"
    echo "Rollback script: ${ROLLBACK_SCRIPT}"
    echo ""
    echo "To rollback if needed:"
    echo "  sudo bash ${ROLLBACK_SCRIPT}"
    echo ""
else
    # Auto-rollback on failure
    echo ""
    echo "=========================================================="
    echo "  ✗ Update Failed - Auto-Rolling Back"
    echo "=========================================================="
    echo ""
    echo "Health check failed after ${HEALTH_TIMEOUT}s"
    echo "Current status: ${HEALTH}"
    echo ""
    echo "Container logs (last 20 lines):"
    echo "---"
    docker logs coolify --tail 20 2>&1 | sed 's/^/  /'
    echo "---"
    echo ""
    echo "Automatically rolling back to original image..."

    # Execute rollback
    cd /data/coolify/source
    rm -f docker-compose.custom.yml

    docker compose --env-file .env \
      -f docker-compose.yml \
      -f docker-compose.prod.yml \
      up -d --force-recreate coolify

    echo ""
    echo "Waiting for rollback to complete..."
    sleep 10

    ROLLBACK_HEALTH=$(docker inspect --format='{{.State.Health.Status}}' coolify 2>/dev/null || echo "unknown")

    if [ "$ROLLBACK_HEALTH" = "healthy" ]; then
        echo "✓ Rollback successful! Coolify is running with original image."
    else
        echo "⚠ Rollback completed but health check uncertain. Status: ${ROLLBACK_HEALTH}"
        echo "  Please check logs: docker logs coolify"
    fi

    echo ""
    echo "Update failed and was rolled back."
    echo "Backup preserved at: ${BACKUP_DIR}"
    echo ""
    echo "Please investigate the issue before trying again."
    echo "Check logs with: docker logs coolify"
    echo ""

    exit 1
fi
