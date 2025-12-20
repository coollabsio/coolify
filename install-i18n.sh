#!/bin/bash
# ==============================================================================
# Coolify i18n Custom Installation Script
# ==============================================================================
# This script installs Coolify using your custom i18n Docker image:
#   - Image: docker.io/a3180623/coolify:i18n
#   - Registry: Docker Hub (docker.io)
#
# Usage:
#   sudo bash install-i18n.sh
# ==============================================================================

set -e

echo ""
echo "=========================================================="
echo "  Coolify i18n Custom Installation"
echo "=========================================================="
echo ""
echo "Custom Image: docker.io/a3180623/coolify:i18n"
echo "Registry: Docker Hub"
echo ""

# Step 1: Set environment variables for custom registry and version
export REGISTRY_URL="docker.io"
CUSTOM_VERSION="i18n"

echo "[1/3] Running standard Coolify installation with version: $CUSTOM_VERSION"
echo ""

# Step 2: Run the original installation script
# This will install Docker, configure the system, and download compose files
bash "$(dirname "$0")/install (1).sh" "$CUSTOM_VERSION"

# Step 3: Create custom docker-compose override to use your image
echo ""
echo "[2/3] Creating custom docker-compose override for a3180623/coolify:i18n"

mkdir -p /data/coolify/source

cat > /data/coolify/source/docker-compose.custom.yml <<'EOF'
# Custom override to use a3180623/coolify:i18n image
services:
  coolify:
    image: "docker.io/a3180623/coolify:i18n"
EOF

echo "     Custom docker-compose.custom.yml created successfully!"
echo "     Image override: docker.io/a3180623/coolify:i18n"

# Step 4: Restart Coolify to apply custom image
echo ""
echo "[3/3] Restarting Coolify with custom i18n image..."
cd /data/coolify/source

docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.custom.yml \
  pull coolify

docker compose --env-file .env \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.custom.yml \
  up -d --force-recreate coolify

echo ""
echo "=========================================================="
echo "  Installation Complete!"
echo "=========================================================="
echo ""
echo "Your custom Coolify i18n instance is now running!"
echo "Image: docker.io/a3180623/coolify:i18n"
echo ""
echo "To verify the installation:"
echo "  docker ps | grep coolify"
echo "  docker logs coolify"
echo ""
echo "Access Coolify at: http://YOUR_SERVER_IP:8000"
echo ""
