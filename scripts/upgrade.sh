#!/bin/bash
## Do not modify this file. You will lose the ability to autoupdate!

CDN="https://cdn.coollabs.io/coolify"
LATEST_IMAGE=${1:-latest}
LATEST_HELPER_VERSION=${2:-latest}
REGISTRY_URL=${3:-ghcr.io}
SKIP_BACKUP=${4:-false}
ENV_FILE="/data/coolify/source/.env"
STATUS_FILE="/data/coolify/source/.upgrade-status"

DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="/data/coolify/source/upgrade-${DATE}.log"

# Helper function to log with timestamp
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >>"$LOGFILE"
}

# Helper function to log section headers
log_section() {
    echo "" >>"$LOGFILE"
    echo "============================================================" >>"$LOGFILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >>"$LOGFILE"
    echo "============================================================" >>"$LOGFILE"
}

# Helper function to write upgrade status for API polling
write_status() {
    local step="$1"
    local message="$2"
    echo "${step}|${message}|$(date -Iseconds)" > "$STATUS_FILE"
}

echo ""
echo "=========================================="
echo "   Coolify Upgrade - ${DATE}"
echo "=========================================="
echo ""

# Initialize log file with header
echo "============================================================" >>"$LOGFILE"
echo "Coolify Upgrade Log" >>"$LOGFILE"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S')" >>"$LOGFILE"
echo "Target Version: ${LATEST_IMAGE}" >>"$LOGFILE"
echo "Helper Version: ${LATEST_HELPER_VERSION}" >>"$LOGFILE"
echo "Registry URL: ${REGISTRY_URL}" >>"$LOGFILE"
echo "============================================================" >>"$LOGFILE"

log_section "Step 1/6: Downloading configuration files"
write_status "1" "Downloading configuration files"
echo "1/6 Downloading latest configuration files..."
log "Downloading docker-compose.yml from ${CDN}/docker-compose.yml"
curl -fsSL -L $CDN/docker-compose.yml -o /data/coolify/source/docker-compose.yml
log "Downloading docker-compose.prod.yml from ${CDN}/docker-compose.prod.yml"
curl -fsSL -L $CDN/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml
log "Downloading .env.production from ${CDN}/.env.production"
curl -fsSL -L $CDN/.env.production -o /data/coolify/source/.env.production
log "Configuration files downloaded successfully"
echo "     Done."

# Extract all images from docker-compose configuration
log "Extracting all images from docker-compose configuration..."
COMPOSE_FILES="-f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml"

# Check if custom compose file exists
if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
    COMPOSE_FILES="$COMPOSE_FILES -f /data/coolify/source/docker-compose.custom.yml"
    log "Including custom docker-compose.yml in image extraction"
fi

# Get all unique images from docker compose config
# LATEST_IMAGE env var is needed for image substitution in compose files
IMAGES=$(LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file "$ENV_FILE" $COMPOSE_FILES config --images 2>/dev/null | sort -u)

if [ -z "$IMAGES" ]; then
    log "ERROR: Failed to extract images from docker-compose files"
    write_status "error" "Failed to parse docker-compose configuration"
    echo "     ERROR: Failed to parse docker-compose configuration. Aborting upgrade."
    exit 1
fi

log "Images to pull:"
echo "$IMAGES" | while read img; do log "  - $img"; done

# Backup existing .env file before making any changes
if [ "$SKIP_BACKUP" != "true" ]; then
    if [ -f "$ENV_FILE" ]; then
        echo "     Creating backup of .env file..."
        log "Creating backup of .env file to .env-$DATE"
        cp "$ENV_FILE" "$ENV_FILE-$DATE"
        log "Backup created: ${ENV_FILE}-${DATE}"
    else
        log "WARNING: No existing .env file found to backup"
    fi
fi

log_section "Step 2/6: Updating environment configuration"
write_status "2" "Updating environment configuration"
echo ""
echo "2/6 Updating environment configuration..."
log "Merging .env.production values into .env"
awk -F '=' '!seen[$1]++' "$ENV_FILE" /data/coolify/source/.env.production > "$ENV_FILE.tmp" && mv "$ENV_FILE.tmp" "$ENV_FILE"
log "Environment file merged successfully"

update_env_var() {
    local key="$1"
    local value="$2"

    # If variable "key=" exists but has no value, update the value of the existing line
    if grep -q "^${key}=$" "$ENV_FILE"; then
        sed -i "s|^${key}=$|${key}=${value}|" "$ENV_FILE"
        log "Updated ${key} (was empty)"
    # If variable "key=" doesn't exist, append it to the file with value
    elif ! grep -q "^${key}=" "$ENV_FILE"; then
        printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
        log "Added ${key} (was missing)"
    fi
}

log "Checking environment variables..."
update_env_var "PUSHER_APP_ID" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
update_env_var "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
log "Environment variables check complete"
echo "     Done."

# Make sure coolify network exists
# It is created when starting Coolify with docker compose
log "Checking Docker network 'coolify'..."
if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Network 'coolify' does not exist, creating..."
    if ! docker network create --attachable --ipv6 coolify 2>/dev/null; then
        log "Failed to create network with IPv6, trying without IPv6..."
        docker network create --attachable coolify 2>/dev/null
        log "Network 'coolify' created without IPv6"
    else
        log "Network 'coolify' created with IPv6 support"
    fi
else
    log "Network 'coolify' already exists"
fi

# Check if Docker config file exists
DOCKER_CONFIG_MOUNT=""
if [ -f /root/.docker/config.json ]; then
    DOCKER_CONFIG_MOUNT="-v /root/.docker/config.json:/root/.docker/config.json"
    log "Docker config mount enabled: /root/.docker/config.json"
fi

log_section "Step 3/6: Pulling Docker images"
write_status "3" "Pulling Docker images"
echo ""
echo "3/6 Pulling Docker images..."
echo "     This may take a few minutes depending on your connection."

# Also pull the helper image (not in compose files but needed for upgrade)
HELPER_IMAGE="${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:${LATEST_HELPER_VERSION}"
echo "     - Pulling $HELPER_IMAGE..."
log "Pulling image: $HELPER_IMAGE"
if docker pull "$HELPER_IMAGE" >>"$LOGFILE" 2>&1; then
    log "Successfully pulled $HELPER_IMAGE"
else
    log "ERROR: Failed to pull $HELPER_IMAGE"
    write_status "error" "Failed to pull $HELPER_IMAGE"
    echo "     ERROR: Failed to pull $HELPER_IMAGE. Aborting upgrade."
    exit 1
fi

# Pull all images from compose config
# Using a for loop to avoid subshell issues with exit
for IMAGE in $IMAGES; do
    if [ -n "$IMAGE" ]; then
        echo "     - Pulling $IMAGE..."
        log "Pulling image: $IMAGE"
        if docker pull "$IMAGE" >>"$LOGFILE" 2>&1; then
            log "Successfully pulled $IMAGE"
        else
            log "ERROR: Failed to pull $IMAGE"
            write_status "error" "Failed to pull $IMAGE"
            echo "     ERROR: Failed to pull $IMAGE. Aborting upgrade."
            exit 1
        fi
    fi
done

log "All images pulled successfully"
echo "     All images pulled successfully."

log_section "Step 4/6: Stopping and restarting containers"
write_status "4" "Stopping containers"
echo ""
echo "4/6 Stopping containers and starting new ones..."
echo "     This step will restart all Coolify containers."
echo "     Check the log file for details: ${LOGFILE}"

# From this point forward, we need to ensure the script continues even if
# the SSH connection is lost (which happens when coolify container stops)
# We use a subshell with nohup to ensure completion
log "Starting container restart sequence (detached)..."

nohup bash -c "
    LOGFILE='$LOGFILE'
    STATUS_FILE='$STATUS_FILE'
    DOCKER_CONFIG_MOUNT='$DOCKER_CONFIG_MOUNT'
    REGISTRY_URL='$REGISTRY_URL'
    LATEST_HELPER_VERSION='$LATEST_HELPER_VERSION'
    LATEST_IMAGE='$LATEST_IMAGE'

    log() {
        echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] \$1\" >>\"\$LOGFILE\"
    }

    write_status() {
        echo \"\$1|\$2|\$(date -Iseconds)\" > \"\$STATUS_FILE\"
    }

    # Stop and remove containers
    for container in coolify coolify-db coolify-redis coolify-realtime; do
        if docker ps -a --format '{{.Names}}' | grep -q \"^\${container}\$\"; then
            log \"Stopping container: \${container}\"
            docker stop \"\$container\" >>\"\$LOGFILE\" 2>&1 || true
            log \"Removing container: \${container}\"
            docker rm \"\$container\" >>\"\$LOGFILE\" 2>&1 || true
            log \"Container \${container} stopped and removed\"
        else
            log \"Container \${container} not found (skipping)\"
        fi
    done
    log \"Container cleanup complete\"

    # Start new containers
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    log 'Step 5/6: Starting new containers'
    echo '============================================================' >>\"\$LOGFILE\"
    write_status '5' 'Starting new containers'

    if [ -f /data/coolify/source/docker-compose.custom.yml ]; then
        log 'Using custom docker-compose.yml'
        log 'Running docker compose up with custom configuration...'
        docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock \${DOCKER_CONFIG_MOUNT} --rm \${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:\${LATEST_HELPER_VERSION} bash -c \"LATEST_IMAGE=\${LATEST_IMAGE} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml -f /data/coolify/source/docker-compose.custom.yml up -d --remove-orphans --wait --wait-timeout 60\" >>\"\$LOGFILE\" 2>&1
    else
        log 'Using standard docker-compose configuration'
        log 'Running docker compose up...'
        docker run -v /data/coolify/source:/data/coolify/source -v /var/run/docker.sock:/var/run/docker.sock \${DOCKER_CONFIG_MOUNT} --rm \${REGISTRY_URL:-ghcr.io}/coollabsio/coolify-helper:\${LATEST_HELPER_VERSION} bash -c \"LATEST_IMAGE=\${LATEST_IMAGE} docker compose --env-file /data/coolify/source/.env -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml up -d --remove-orphans --wait --wait-timeout 60\" >>\"\$LOGFILE\" 2>&1
    fi
    log 'Docker compose up completed'

    # Final log entry
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    log 'Step 6/6: Upgrade complete'
    echo '============================================================' >>\"\$LOGFILE\"
    write_status '6' 'Upgrade complete'
    log 'Coolify upgrade completed successfully'
    log \"Version: \${LATEST_IMAGE}\"
    echo '' >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"
    echo \"Upgrade completed: \$(date '+%Y-%m-%d %H:%M:%S')\" >>\"\$LOGFILE\"
    echo '============================================================' >>\"\$LOGFILE\"

    # Clean up status file after a short delay to allow frontend to read completion
    sleep 10
    rm -f \"\$STATUS_FILE\"
    log 'Status file cleaned up'
" >>"$LOGFILE" 2>&1 &

# Give the background process a moment to start
sleep 2
log "Container restart sequence started in background (PID: $!)"
echo ""
echo "5/6 Containers are being restarted in the background..."
echo "6/6 Upgrade process initiated!"
echo ""
echo "=========================================="
echo "   Coolify upgrade to ${LATEST_IMAGE} in progress"
echo "=========================================="
echo ""
echo "   The upgrade will continue in the background."
echo "   Coolify will be available again shortly."
echo "   Log file: ${LOGFILE}"
