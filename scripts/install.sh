#!/bin/bash
## Do not modify this file. You will lose the ability to install and auto-update!

## Environment variables that can be set:
## ROOT_USERNAME - Predefined root username
## ROOT_USER_EMAIL - Predefined root user email
## ROOT_USER_PASSWORD - Predefined root user password
## DOCKER_ADDRESS_POOL_BASE - Custom Docker address pool base (default: 10.0.0.0/8)
## DOCKER_ADDRESS_POOL_SIZE - Custom Docker address pool size (default: 24)
## DOCKER_POOL_FORCE_OVERRIDE - Force override Docker address pool configuration (default: false)
## AUTOUPDATE - Set to "false" to disable auto-updates
## REGISTRY_URL - Custom registry URL for Docker images (default: ghcr.io)

set -e
set -o pipefail
CDN="https://cdn.coollabs.io/coolify"
DATE=$(date +"%Y%m%d-%H%M%S")

OS_TYPE=$(grep -w "ID" /etc/os-release | cut -d "=" -f 2 | tr -d '"')
ENV_FILE="/data/coolify/source/.env"
DOCKER_VERSION="27.0"
CURRENT_USER=$USER

if [ $EUID != 0 ]; then
    echo "Please run this script as root or with sudo"
    exit
fi

echo -e "Welcome to Coolify Installer!"
echo -e "This script will install everything for you. Sit back and relax."
echo -e "Source code: https://github.com/coollabsio/coolify/blob/v4.x/scripts/install.sh"

# Predefined root user
ROOT_USERNAME=${ROOT_USERNAME:-}
ROOT_USER_EMAIL=${ROOT_USER_EMAIL:-}
ROOT_USER_PASSWORD=${ROOT_USER_PASSWORD:-}

if [ -n "${REGISTRY_URL+x}" ]; then
    echo "Using registry URL from environment variable: $REGISTRY_URL"
else
    if [ -f "$ENV_FILE" ] && grep -q "^REGISTRY_URL=" "$ENV_FILE"; then
        REGISTRY_URL=$(grep "^REGISTRY_URL=" "$ENV_FILE" | cut -d '=' -f2)
        echo "Using registry URL from .env: $REGISTRY_URL"
    else
        REGISTRY_URL="ghcr.io"
        echo "Using default registry URL: $REGISTRY_URL"
    fi
fi

# Docker address pool defaults, setup, checks...
# (content unchanged for brevity — same as your original)
# --------------------------------------------------------
#   [ ... all code here unchanged ... ]
# --------------------------------------------------------

# Existing install logic up to:
echo " - Coolify installed successfully."

# 🧠 --- NEW SECTION ADDED BELOW ---
# Post-installation sanity checks and clear output for users
echo " - Running final installation checks..."

# Function to check if a port is in use
check_port() {
  local port=$1
  if ss -tuln 2>/dev/null | grep -q ":$port "; then
    echo "⚠️  Port $port is already in use. This may prevent Coolify from starting correctly."
    echo "   Please free this port or set APP_PORT in /data/coolify/source/.env to another value."
  fi
}


# 1. Check for port conflicts
check_port 80
check_port 8000

# 2. Verify that the main container started properly
sleep 5
if ! docker ps --format '{{.Names}}' | grep -q '^coolify$'; then
  echo "❌ Coolify container did not start successfully."
  echo "Common causes:"
  echo " • Port 80/8000 already in use (e.g. nginx, portainer)."
  echo " • Docker registry or network issues."
  echo " • Insufficient permissions or runtime errors."
  echo
  echo "Troubleshooting tips:"
  echo " • Run:  docker ps -a | grep coolify"
  echo " • Logs: cat /data/coolify/source/upgrade-*.log"
  echo
  echo "Once resolved, re-run the installer or run:"
  echo "    bash /data/coolify/source/upgrade.sh"
  exit 1
fi

# 🧩 If the container is running, continue with success messages
echo "✅ Coolify containers started successfully."
echo " - Waiting 20 seconds for Coolify database migrations to complete."
getAJoke
sleep 20

echo -e "\033[0;35m
   ____                            _         _       _   _                 _
  / ___|___  _ __   __ _ _ __ __ _| |_ _   _| | __ _| |_(_) ___  _ __  ___| |
 | |   / _ \| '_ \ / _\` | '__/ _\` | __| | | | |/ _\` | __| |/ _ \| '_ \/ __| |
 | |__| (_) | | | | (_| | | | (_| | |_| |_| | | (_| | |_| | (_) | | | \__ \_|
  \____\___/|_| |_|\__, |_|  \__,_|\__|\__,_|_|\__,_|\__|_|\___/|_| |_|___(_)
                   |___/
\033[0m"

# Fetch IPs (same logic but simplified for clarity)
IPV4_PUBLIC_IP=$(curl -4s https://ifconfig.io || true)
IPV6_PUBLIC_IP=$(curl -6s https://ifconfig.io || true)
LOCAL_IP=$(hostname -I | awk '{print $1}')

echo -e "\n🎉 Your Coolify instance is ready!\n"
if [ -n "$IPV4_PUBLIC_IP" ]; then
  echo "🌐 Access via Public IPv4: http://$IPV4_PUBLIC_IP:8000"
fi
if [ -n "$IPV6_PUBLIC_IP" ]; then
  echo "🌐 Access via Public IPv6: http://[$IPV6_PUBLIC_IP]:8000"
fi
if [ -n "$LOCAL_IP" ]; then
  echo "💻 Local access (LAN): http://$LOCAL_IP:8000"
fi

echo -e "\n⚠️  If the above links do not work, verify Docker containers are running:"
echo "   docker ps | grep coolify"
echo -e "\nBackup your environment file at /data/coolify/source/.env for safety.\n"
echo "✅ Installation completed successfully."

# --- END OF NEW SECTION ---
