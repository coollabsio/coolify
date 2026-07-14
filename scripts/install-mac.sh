#!/bin/bash
## Coolify installer for macOS (Docker Desktop / OrbStack).
##
## macOS cannot run the standard install.sh: there is no /etc/os-release, no
## systemd, the root filesystem is read-only (no /data), and Docker runs inside
## a per-user VM instead of as a system daemon. This script instead uses
## Coolify's Docker Desktop mode (the same mechanism as docker-compose.windows.yml):
## a "coolify-testing-host" container with the Docker socket mounted acts as the
## managed "localhost" server, so no SSH access to the Mac itself is needed.
##
## Usage:
##   ./scripts/install-mac.sh          # from a checkout of the repository
##
## Environment variables:
##   APP_PORT    - Port for the Coolify UI (default: 8000)
##
## Requirements:
##   - macOS with Docker Desktop or OrbStack installed and running
##   - Docker Engine 24+ with Compose v2

set -e
set -o pipefail

if [ "$(uname -s)" != "Darwin" ]; then
    echo "This installer is for macOS only. On Linux, use scripts/install.sh instead."
    exit 1
fi

if [ "$EUID" = 0 ]; then
    echo "Do not run this script as root. Docker Desktop and OrbStack run per-user;"
    echo "run it as your normal user."
    exit 1
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/docker-compose.mac.yml"
ENV_EXAMPLE="$REPO_ROOT/.env.mac-docker-desktop.example"
ENV_FILE="$REPO_ROOT/.env"

echo ""
echo "=========================================="
echo "   Coolify Installation for macOS"
echo "=========================================="
echo ""

if [ ! -f "$COMPOSE_FILE" ]; then
    echo "ERROR: $COMPOSE_FILE not found."
    echo "Run this script from a checkout of the Coolify repository."
    exit 1
fi

echo "1/6 Checking Docker installation..."
if ! command -v docker >/dev/null 2>&1; then
    echo " - Docker is not installed."
    echo "   Install one of the following and re-run this script:"
    echo "   - OrbStack (recommended, lighter/faster): https://orbstack.dev"
    echo "   - Docker Desktop for Mac: https://docs.docker.com/desktop/install/mac-install/"
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo " - Docker is installed but the daemon is not running."
    echo "   Start Docker Desktop (or OrbStack) and re-run this script."
    exit 1
fi
echo " - Docker daemon is running."

if ! docker compose version >/dev/null 2>&1; then
    echo " - Docker Compose v2 is required but was not found."
    echo "   Update Docker Desktop / OrbStack to a recent version."
    exit 1
fi

MIN_DOCKER_VERSION=24
INSTALLED_DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null | cut -d. -f1)
if [ -z "$INSTALLED_DOCKER_VERSION" ]; then
    echo " - WARNING: Could not determine Docker version. Coolify requires Docker ${MIN_DOCKER_VERSION}+."
elif [ "$INSTALLED_DOCKER_VERSION" -lt "$MIN_DOCKER_VERSION" ]; then
    echo " - ERROR: Docker version $(docker version --format '{{.Server.Version}}') is too old."
    echo "   Coolify requires Docker ${MIN_DOCKER_VERSION} or newer. Update Docker Desktop / OrbStack."
    exit 1
else
    echo " - Docker version $(docker version --format '{{.Server.Version}}') meets the minimum requirement (${MIN_DOCKER_VERSION}+)."
fi

echo "2/6 Preparing data directories..."
# The repository root is bind-mounted as /data/coolify inside the
# coolify-testing-host container; these subdirectories mirror what install.sh
# creates under /data/coolify on Linux.
mkdir -p "$REPO_ROOT"/{ssh,applications,databases,backups,services,proxy,sentinel}
mkdir -p "$REPO_ROOT"/ssh/{keys,mux}
mkdir -p "$REPO_ROOT"/proxy/dynamic
echo "     Done."

echo "3/6 Setting up the environment file..."
# update_env_var: fill KEY= when empty, append KEY=value when missing.
update_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=$" "$ENV_FILE"; then
        # BSD sed: -i requires an explicit (empty) backup suffix
        sed -i '' "s|^${key}=$|${key}=${value}|" "$ENV_FILE"
        echo " - Generated a value for ${key}"
    elif ! grep -q "^${key}=" "$ENV_FILE"; then
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        echo " - Added missing variable ${key}"
    fi
}

if [ -f "$ENV_FILE" ]; then
    echo " - Reusing existing .env file."
else
    cp "$ENV_EXAMPLE" "$ENV_FILE"
    echo " - Created .env from $(basename "$ENV_EXAMPLE")."
fi

update_env_var "APP_KEY" "base64:$(openssl rand -base64 32)"
update_env_var "DB_PASSWORD" "$(openssl rand -hex 16)"
update_env_var "REDIS_PASSWORD" "$(openssl rand -hex 16)"
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
echo "     Done."

echo "4/6 Pulling Coolify images (this may take a while)..."
docker compose -f "$COMPOSE_FILE" pull --quiet
echo "     Done."

echo "5/6 Starting Coolify..."
docker compose -f "$COMPOSE_FILE" up -d --remove-orphans
echo "     Done."

echo "6/6 Waiting for Coolify to become healthy..."
APP_PORT=${APP_PORT:-8000}
MAX_WAIT=300
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if curl -fsSL "http://localhost:${APP_PORT}/api/health" >/dev/null 2>&1; then
        break
    fi
    sleep 3
    WAITED=$((WAITED + 3))
done

echo ""
if [ $WAITED -ge $MAX_WAIT ]; then
    echo "Coolify did not become healthy within ${MAX_WAIT}s."
    echo "Check the logs with: docker compose -f docker-compose.mac.yml logs -f coolify"
    exit 1
fi

echo "=========================================="
echo "Coolify is up and running!"
echo ""
echo "  Dashboard:  http://localhost:${APP_PORT}"
echo ""
echo "Notes:"
echo "  - Open the dashboard now to create the root user."
echo "  - The 'localhost' server in Coolify is the coolify-testing-host container,"
echo "    which controls your Mac's Docker via the mounted Docker socket."
echo "  - Deployed apps run as regular containers in Docker Desktop / OrbStack."
echo "  - Data lives in this directory (ssh/, applications/, databases/, backups/)"
echo "    and in the coolify-db / coolify-redis Docker volumes."
echo ""
echo "Manage the stack:"
echo "  Stop:      docker compose -f docker-compose.mac.yml down"
echo "  Logs:      docker compose -f docker-compose.mac.yml logs -f"
echo "  Uninstall: docker compose -f docker-compose.mac.yml down -v"
echo "=========================================="
