#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

CDN="https://cdn.coollabs.io/coolify"
LATEST_IMAGE=${1:-latest}
LATEST_HELPER_VERSION=${2:-latest}
REGISTRY_URL=${3:-ghcr.io}
SKIP_BACKUP=${4:-false}
ENV_FILE="/data/coolify/source/.env"

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="/data/coolify/source/upgrade-${DATE}.log"

echo ""
echo "=========================================="
echo "   Coolify Upgrade - ${DATE}"
echo "=========================================="
echo ""

echo "1/6 Downloading latest configuration files..."
curl -fsSL -L $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL -L $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL -L $CDN/.env.production -o /data/coolify/source/.env.production
echo "     Done."

# Backup existing .env file before making any changes
if [ "$SKIP_BACKUP" != "true" ]; then
    if [ -f "$ENV_FILE" ]; then
        echo "     Creating backup of .env file..."
        echo "Creating backup of existing .env file to .env-$DATE" >>"$LOGFILE"
        cp "$ENV_FILE" "$ENV_FILE-$DATE"
    else
        echo "No existing .env file found to backup" >>"$LOGFILE"
    fi
fi

echo ""
echo "2/6 Updating environment configuration..."
echo "Merging .env.production values into .env" >>"$LOGFILE"
awk -F '=' '!seen[$1]++' "$ENV_FILE" /data/coolify/source/.env.production > "$ENV_FILE.tmp" && mv "$ENV_FILE.tmp" "$ENV_FILE"
echo ".env file merged successfully" >>"$LOGFILE"

update_env_var() {
    local key="$1"
    local value="$2"

    # If variable "key=" exists but has no value, update the value of the existing line
    if grep -q "^${key}=$" "$ENV_FILE"; then
        sed -i "s|^${key}=$|${key}=${value}|" "$ENV_FILE"
        echo " - Updated value of ${key} as the current value was empty" >>"$LOGFILE"
    # If variable "key=" doesn't exist, append it to the file with value
    elif ! grep -q "^${key}=" "$ENV_FILE"; then
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        echo " - Added ${key} with default value as the variable was missing" >>"$LOGFILE"
    fi
}

echo "Checking and updating environment variables if necessary..." >>"$LOGFILE"
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
echo "     Done."

# Make sure coolify network exists
# It is created when starting Coolify with docker compose
if ! docker network inspect coolify >/dev/null 2>&1; then
    if ! docker network create --attachable --ipv6 coolify 2>/dev/null; then
        echo "Failed to create coolify network with ipv6. Trying without ipv6..." >>"$LOGFILE"
        docker network create --attachable coolify 2>/dev/null
    fi
fi

# Check if Docker config file exists
DOCKER_CONFIG_MOUNT=""
if [ -f /root/.docker/config.json ]; then
    DOCKER_CONFIG_MOUNT="-v /root/.docker/config.json:/root/.docker/config.json"
fi

echo ""
echo "3/6 Pulling Docker images..."
echo "     This may take a few minutes depending on your connection."
echo "Pulling required Docker images..." >>"$LOGFILE"

echo "     - Pulling Coolify image..."
docker pull "${REGISTRY_URL:-ghcr.io}/coollabsio/coolify:${LATEST_IMAGE}" >>"$LOGFILE" 2>&1 || { echo "     ERROR: Failed to pull Coolify image. Aborting upgrade."; echo "Failed to pull Coolify image. Aborting upgrade." >>"$LOGFILE"; exit 1; }

echo "     - Pulling Coolify helper image..."
docker pull "${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION}" >>"$LOGFILE" 2>&1 || { echo "     ERROR: Failed to pull helper image. Aborting upgrade."; echo "Failed to pull Coolify helper image. Aborting upgrade." >>"$LOGFILE"; exit 1; }

echo "     - Pulling PostgreSQL image..."
docker pull postgres:15-alpine >>"$LOGFILE" 2>&1 || { echo "     ERROR: Failed to pull PostgreSQL image. Aborting upgrade."; echo "Failed to pull PostgreSQL image. Aborting upgrade." >>"$LOGFILE"; exit 1; }

echo "     - Pulling Redis image..."
docker pull redis:7-alpine >>"$LOGFILE" 2>&1 || { echo "     ERROR: Failed to pull Redis image. Aborting upgrade."; echo "Failed to pull Redis image. Aborting upgrade." >>"$LOGFILE"; exit 1; }

echo "     - Pulling Coolify realtime image..."
docker pull "${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-realtime:1.0.10" >>"$LOGFILE" 2>&1 || { echo "     ERROR: Failed to pull realtime image. Aborting upgrade."; echo "Failed to pull Coolify realtime image. Aborting upgrade." >>"$LOGFILE"; exit 1; }

echo "All images pulled successfully." >>"$LOGFILE"
echo "     All images pulled successfully."

echo ""
echo "4/6 Stopping existing containers..."
echo "Stopping existing Coolify containers..." >>"$LOGFILE"
for container in coolify coolify-db coolify-redis coolify-realtime; do
    if docker ps -a --format '{{.Names}}' | grep -q "^${container}$"; then
        echo "     - Stopping ${container}..."
        docker stop "$container" >>"$LOGFILE" 2>&1 || true
        docker rm "$container" >>"$LOGFILE" 2>&1 || true
        echo " - Removed container: $container" >>"$LOGFILE"
    fi
done
echo "     Done."

echo ""
echo "5/6 Starting new containers..."
if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    echo "     Custom docker-compose.yml detected."
    echo "docker-compose.custom.yml detected." >>"$LOGFILE"
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock ${DOCKER_CONFIG_MOUNT} --rm ${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --project-name coolify --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml -f /data/coolify/source/docker-compose.custom.yml up -d --remove-orphans --wait --wait-timeout 60" >>"$LOGFILE" 2>&1
else
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock ${DOCKER_CONFIG_MOUNT} --rm ${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --project-name coolify --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --remove-orphans --wait --wait-timeout 60" >>"$LOGFILE" 2>&1
fi
echo "     Done."

echo ""
echo "6/6 Upgrade complete!"
echo ""
echo "=========================================="
echo "   Coolify has been upgraded to ${LATEST_IMAGE}"
echo "=========================================="
echo ""
echo "   Log file: ${LOGFILE}"
echo ""
