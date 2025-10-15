#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

CDN="https://cdn.coollabs.io/coolify-nightly"
LATEST_IMAGE=${1:-latest}
LATEST_HELPER_VERSION=${2:-latest}
REGISTRY_URL=${3:-ghcr.io}
SKIP_BACKUP=${4:-false}
ENV_FILE="/data/coolify/source/.env"

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="/data/coolify/source/upgrade-${DATE}.log"

curl -fsSL $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
curl -fsSL $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
curl -fsSL $CDN/.env.production -o /data/coolify/source/.env.production

# Backup existing .env file before making any changes
if [ "$SKIP_BACKUP" != "true" ]; then
    if [ -f "$ENV_FILE" ]; then
        echo "Creating backup of existing .env file to .env-$DATE" >>"$LOGFILE"
        cp "$ENV_FILE" "$ENV_FILE-$DATE"
    else
        echo "No existing .env file found to backup" >>"$LOGFILE"
    fi
fi

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

# Make sure coolify network exists
# It is created when starting Coolify with docker compose
if ! docker network inspect coolify >/dev/null 2>&1; then
    if ! docker network create --attachable --ipv6 coolify 2>/dev/null; then
        echo "Failed to create coolify network with ipv6. Trying without ipv6..."
        docker network create --attachable coolify 2>/dev/null
    fi
fi

# Check if Docker config file exists
DOCKER_CONFIG_MOUNT=""
if [ -f /root/.docker/config.json ]; then
    DOCKER_CONFIG_MOUNT="-v /root/.docker/config.json:/root/.docker/config.json"
fi

if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    echo "docker-compose.custom.yml detected." >>"$LOGFILE"
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock ${DOCKER_CONFIG_MOUNT} --rm ${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml -f /data/coolify/source/docker-compose.custom.yml up -d --remove-orphans --force-recreate --wait --wait-timeout 60" >>"$LOGFILE" 2>&1
else
    docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock ${DOCKER_CONFIG_MOUNT} --rm ${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION} bash -c "LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --remove-orphans --force-recreate --wait --wait-timeout 60" >>"$LOGFILE" 2>&1
fi
