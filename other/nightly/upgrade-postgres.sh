#!/bin/bash
## Explicit Coolify internal PostgreSQL major-version migrator.
## This script is intentionally not run by upgrade.sh automatically.

set -Eeuo pipefail

SOURCE_DIR="/data/coolify/source"
ENV_FILE="${SOURCE_DIR}/.env"
BACKUP_DIR="/data/coolify/backups/internal-postgres"
OVERRIDE_FILE="${SOURCE_DIR}/docker-compose.postgres-upgrade.yml"
ROLLBACK_FILE="${SOURCE_DIR}/postgres-upgrade-rollback.env"
DATE=$(date +%Y-%m-%d-%H-%M-%S)
LOGFILE="${SOURCE_DIR}/postgres-upgrade-${DATE}.log"
COMMAND="${1:-upgrade}"
TARGET_MAJOR="${1:-18}"

if [ "$COMMAND" = "rollback" ]; then
    TARGET_MAJOR=""
else
    COMMAND="upgrade"
fi

TARGET_IMAGE="${COOLIFY_POSTGRES_TARGET_IMAGE:-postgres:${TARGET_MAJOR}-alpine}"
TARGET_VOLUME="${COOLIFY_POSTGRES_TARGET_VOLUME:-coolify-db-pg${TARGET_MAJOR}}"
TEMP_CONTAINER="coolify-db-pg${TARGET_MAJOR:-rollback}-restore-${DATE}"
DUMP_FILE="${BACKUP_DIR}/postgres-upgrade-${DATE}.sql.gz"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOGFILE"
}

fail() {
    log "ERROR: $1"
    exit 1
}

usage() {
    cat <<EOF
Usage:
  $0 <target-major>
  $0 rollback

Examples:
  $0 18
  $0 rollback

Environment overrides:
  COOLIFY_POSTGRES_TARGET_IMAGE=postgres:18-alpine
  COOLIFY_POSTGRES_TARGET_VOLUME=coolify-db-pg18
EOF
}

cleanup() {
    docker rm -f "$TEMP_CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

get_env_var() {
    local key="$1"
    local fallback="${2:-}"
    local value

    value=$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d '=' -f 2- || true)
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    if [ -z "$value" ]; then
        printf '%s' "$fallback"
    else
        printf '%s' "$value"
    fi
}

wait_for_postgres() {
    local container="$1"
    local user="$2"
    local database="$3"
    local attempts=60

    for _ in $(seq 1 "$attempts"); do
        if docker exec "$container" pg_isready -U "$user" -d "$database" >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    return 1
}

compose_files() {
    printf -- '-f %s/docker-compose.yml -f %s/docker-compose.prod.yml ' "$SOURCE_DIR" "$SOURCE_DIR"

    if [ -f "${SOURCE_DIR}/docker-compose.custom.yml" ]; then
        printf -- '-f %s/docker-compose.custom.yml ' "$SOURCE_DIR"
    fi

    if [ -f "$OVERRIDE_FILE" ]; then
        printf -- '-f %s ' "$OVERRIDE_FILE"
    fi
}

validate_target_major() {
    case "$TARGET_MAJOR" in
        ''|*[!0-9]*)
            usage
            fail "Target major version must be numeric. Example: $0 18"
            ;;
    esac

    if [ "$TARGET_MAJOR" -lt 10 ]; then
        fail "Target major version must be 10 or higher."
    fi
}

mount_path_for_major() {
    local major="$1"

    if [ "$major" -ge 18 ]; then
        printf '%s' '/var/lib/postgresql'
    else
        printf '%s' '/var/lib/postgresql/data'
    fi
}

current_postgres_mount_name() {
    docker inspect coolify-db --format '{{range .Mounts}}{{if or (eq .Destination "/var/lib/postgresql/data") (eq .Destination "/var/lib/postgresql")}}{{.Name}}{{end}}{{end}}' 2>/dev/null
}

current_postgres_mount_path() {
    docker inspect coolify-db --format '{{range .Mounts}}{{if or (eq .Destination "/var/lib/postgresql/data") (eq .Destination "/var/lib/postgresql")}}{{.Destination}}{{end}}{{end}}' 2>/dev/null
}

current_postgres_image() {
    docker inspect coolify-db --format '{{.Config.Image}}' 2>/dev/null
}

current_coolify_image_tag() {
    local image
    local image_without_digest
    local last_segment

    image=$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || true)
    image_without_digest="${image%@*}"
    last_segment="${image_without_digest##*/}"

    if [[ "$last_segment" == *:* ]]; then
        printf '%s' "${last_segment##*:}"
    fi
}

write_override_file() {
    local image="$1"
    local volume="$2"
    local mount_path="$3"

    cat > "$OVERRIDE_FILE" <<YAML
services:
  postgres:
    image: "${image}"
    volumes:
      - coolify-db:${mount_path}
volumes:
  coolify-db:
    name: "${volume}"
    external: true
YAML
}

write_rollback_file() {
    local previous_image="$1"
    local previous_volume="$2"
    local previous_mount_path="$3"
    local previous_override_present="$4"
    local upgraded_image="$5"
    local upgraded_volume="$6"

    cat > "$ROLLBACK_FILE" <<EOF
PREVIOUS_IMAGE='${previous_image}'
PREVIOUS_VOLUME='${previous_volume}'
PREVIOUS_MOUNT_PATH='${previous_mount_path}'
PREVIOUS_OVERRIDE_PRESENT='${previous_override_present}'
UPGRADED_IMAGE='${upgraded_image}'
UPGRADED_VOLUME='${upgraded_volume}'
CREATED_AT='${DATE}'
EOF
    chmod 600 "$ROLLBACK_FILE"
}

start_stack() {
    local coolify_image_tag="${1:-${LATEST_IMAGE:-latest}}"
    local files
    files=$(compose_files)

    # shellcheck disable=SC2086
    LATEST_IMAGE="$coolify_image_tag" docker compose --env-file "$ENV_FILE" $files up -d --remove-orphans --wait --wait-timeout 120
}

print_rollback_instructions() {
    cat <<EOF | tee -a "$LOGFILE"

Rollback command if the upgraded database does not work:
  ${SOURCE_DIR}/upgrade-postgres.sh rollback

Rollback metadata was saved to:
  ${ROLLBACK_FILE}

The previous active Docker volume was '${PREVIOUS_VOLUME}'.
The new Docker volume is '${TARGET_VOLUME}'.
The dump file is '${DUMP_FILE}'.
EOF
}

validate_common_requirements() {
    mkdir -p "$BACKUP_DIR"
    touch "$LOGFILE"
    chmod 700 "$BACKUP_DIR"

    [ -f "$ENV_FILE" ] || fail "Missing ${ENV_FILE}. Run this on a self-hosted Coolify server."
    command -v docker >/dev/null 2>&1 || fail "Docker is required."
    docker info >/dev/null 2>&1 || fail "Docker daemon is not reachable."
}

rollback_postgres() {
    validate_common_requirements

    [ -f "$ROLLBACK_FILE" ] || fail "Missing rollback metadata file: ${ROLLBACK_FILE}"

    # shellcheck disable=SC1090
    . "$ROLLBACK_FILE"

    [ -n "${PREVIOUS_IMAGE:-}" ] || fail "Rollback metadata is missing PREVIOUS_IMAGE."
    [ -n "${PREVIOUS_VOLUME:-}" ] || fail "Rollback metadata is missing PREVIOUS_VOLUME."
    [ -n "${PREVIOUS_MOUNT_PATH:-}" ] || fail "Rollback metadata is missing PREVIOUS_MOUNT_PATH."
    [ -n "${PREVIOUS_OVERRIDE_PRESENT:-}" ] || fail "Rollback metadata is missing PREVIOUS_OVERRIDE_PRESENT."

    CURRENT_COOLIFY_IMAGE_TAG=$(current_coolify_image_tag)

    log "Rolling back Coolify internal PostgreSQL."
    log "Previous image: ${PREVIOUS_IMAGE}"
    log "Previous volume: ${PREVIOUS_VOLUME}"
    log "Previous mount path: ${PREVIOUS_MOUNT_PATH}"
    log "Current Coolify image tag: ${CURRENT_COOLIFY_IMAGE_TAG:-latest}"

    docker volume inspect "$PREVIOUS_VOLUME" >/dev/null 2>&1 || fail "Previous volume '${PREVIOUS_VOLUME}' does not exist."

    log "Stopping Coolify application container before rollback."
    docker stop coolify >>"$LOGFILE" 2>&1 || true

    log "Removing current coolify-db container. Current upgraded volume is kept untouched."
    docker rm -f coolify-db >>"$LOGFILE" 2>&1 || true

    if [ "$PREVIOUS_OVERRIDE_PRESENT" = "true" ]; then
        log "Restoring previous PostgreSQL compose override."
        write_override_file "$PREVIOUS_IMAGE" "$PREVIOUS_VOLUME" "$PREVIOUS_MOUNT_PATH"
    else
        log "Removing PostgreSQL compose override to restore base compose configuration."
        rm -f "$OVERRIDE_FILE"
    fi

    log "Starting Coolify stack with rollback database volume."
    start_stack "$CURRENT_COOLIFY_IMAGE_TAG" >>"$LOGFILE" 2>&1 || fail "Could not start Coolify stack after rollback. See ${LOGFILE}."

    log "Rollback completed successfully."
    cat <<EOF | tee -a "$LOGFILE"

Rollback completed.
Current active PostgreSQL volume should be '${PREVIOUS_VOLUME}'.
The upgraded volume '${UPGRADED_VOLUME:-unknown}' was left untouched for inspection or manual cleanup.
EOF
}

upgrade_postgres() {
    validate_target_major
    TARGET_MOUNT_PATH=$(mount_path_for_major "$TARGET_MAJOR")
    validate_common_requirements

    log "Starting Coolify internal PostgreSQL major upgrade."
    log "Target major: ${TARGET_MAJOR}"
    log "Target image: ${TARGET_IMAGE}"
    log "Target volume: ${TARGET_VOLUME}"
    log "Target mount path: ${TARGET_MOUNT_PATH}"

    DB_USERNAME=$(get_env_var DB_USERNAME coolify)
    DB_DATABASE=$(get_env_var DB_DATABASE coolify)

    if ! docker ps -a --format '{{.Names}}' | grep -qx 'coolify-db'; then
        fail "Container 'coolify-db' was not found. Start Coolify before running this script."
    fi

    if ! docker ps --format '{{.Names}}' | grep -qx 'coolify-db'; then
        log "Starting existing coolify-db container for version detection and dump."
        docker start coolify-db >>"$LOGFILE" 2>&1 || fail "Could not start coolify-db."
    fi

    wait_for_postgres coolify-db "$DB_USERNAME" "$DB_DATABASE" || fail "Existing coolify-db is not ready."

    SERVER_VERSION_NUM=$(docker exec coolify-db psql -U "$DB_USERNAME" -d "$DB_DATABASE" -Atc 'SHOW server_version_num;' | tr -d '[:space:]')
    CURRENT_MAJOR=$((SERVER_VERSION_NUM / 10000))
    PREVIOUS_VOLUME=$(current_postgres_mount_name)
    PREVIOUS_MOUNT_PATH=$(current_postgres_mount_path)
    PREVIOUS_IMAGE=$(current_postgres_image)
    CURRENT_COOLIFY_IMAGE_TAG=$(current_coolify_image_tag)

    [ -n "$PREVIOUS_VOLUME" ] || fail "Could not detect current PostgreSQL Docker volume."
    [ -n "$PREVIOUS_MOUNT_PATH" ] || fail "Could not detect current PostgreSQL mount path."
    [ -n "$PREVIOUS_IMAGE" ] || fail "Could not detect current PostgreSQL image."

    if [ -f "$OVERRIDE_FILE" ]; then
        PREVIOUS_OVERRIDE_PRESENT=true
    else
        PREVIOUS_OVERRIDE_PRESENT=false
    fi

    log "Current PostgreSQL major: ${CURRENT_MAJOR}"
    log "Current active volume: ${PREVIOUS_VOLUME}"
    log "Current image: ${PREVIOUS_IMAGE}"
    log "Current mount path: ${PREVIOUS_MOUNT_PATH}"
    log "Current Coolify image tag: ${CURRENT_COOLIFY_IMAGE_TAG:-latest}"

    if [ "$CURRENT_MAJOR" -eq "$TARGET_MAJOR" ]; then
        log "PostgreSQL is already on major ${TARGET_MAJOR}. Nothing to do."
        exit 0
    fi

    if [ "$CURRENT_MAJOR" -gt "$TARGET_MAJOR" ]; then
        fail "Downgrade from ${CURRENT_MAJOR} to ${TARGET_MAJOR} is not supported. Use '$0 rollback' to restore the previous upgrade state."
    fi

    if docker volume inspect "$TARGET_VOLUME" >/dev/null 2>&1; then
        fail "Target volume '${TARGET_VOLUME}' already exists. Set COOLIFY_POSTGRES_TARGET_VOLUME to a new name or remove the old failed target volume."
    fi

    log "Stopping Coolify application container to prevent writes during dump."
    docker stop coolify >>"$LOGFILE" 2>&1 || true

    log "Creating compressed dump at ${DUMP_FILE}."
    docker exec coolify-db pg_dumpall -U "$DB_USERNAME" | gzip -c > "$DUMP_FILE"
    chmod 600 "$DUMP_FILE"

    if [ ! -s "$DUMP_FILE" ]; then
        fail "Dump file is empty. Aborting."
    fi

    log "Creating target Docker volume '${TARGET_VOLUME}'."
    docker volume create "$TARGET_VOLUME" >>"$LOGFILE" 2>&1

    log "Pulling ${TARGET_IMAGE}."
    docker pull "$TARGET_IMAGE" >>"$LOGFILE" 2>&1

    log "Starting temporary PostgreSQL ${TARGET_MAJOR} container."
    docker run -d \
        --name "$TEMP_CONTAINER" \
        --network coolify \
        -e POSTGRES_HOST_AUTH_METHOD=trust \
        -v "${TARGET_VOLUME}:${TARGET_MOUNT_PATH}" \
        "$TARGET_IMAGE" >>"$LOGFILE" 2>&1

    wait_for_postgres "$TEMP_CONTAINER" postgres postgres || fail "Temporary PostgreSQL ${TARGET_MAJOR} container did not become ready."

    log "Restoring dump into target volume."
    gunzip -c "$DUMP_FILE" | docker exec -i "$TEMP_CONTAINER" psql -U postgres -d postgres >>"$LOGFILE" 2>&1

    log "Smoke-checking restored Coolify database."
    docker exec "$TEMP_CONTAINER" psql -U "$DB_USERNAME" -d "$DB_DATABASE" -Atc 'SELECT 1;' | grep -qx '1' || fail "Restored database smoke check failed."

    log "Saving rollback metadata to ${ROLLBACK_FILE}."
    write_rollback_file "$PREVIOUS_IMAGE" "$PREVIOUS_VOLUME" "$PREVIOUS_MOUNT_PATH" "$PREVIOUS_OVERRIDE_PRESENT" "$TARGET_IMAGE" "$TARGET_VOLUME"

    log "Writing Docker Compose override to ${OVERRIDE_FILE}."
    write_override_file "$TARGET_IMAGE" "$TARGET_VOLUME" "$TARGET_MOUNT_PATH"

    log "Stopping temporary restore container."
    docker rm -f "$TEMP_CONTAINER" >>"$LOGFILE" 2>&1 || true

    log "Stopping old coolify-db container. Previous volume '${PREVIOUS_VOLUME}' will be kept for rollback."
    docker rm -f coolify-db >>"$LOGFILE" 2>&1 || true

    log "Starting Coolify stack with PostgreSQL ${TARGET_MAJOR}."
    start_stack "$CURRENT_COOLIFY_IMAGE_TAG" >>"$LOGFILE" 2>&1 || fail "Could not start Coolify stack with upgraded PostgreSQL. See ${LOGFILE}."

    log "Coolify internal PostgreSQL upgrade completed successfully."
    print_rollback_instructions
}

if [ "${COOLIFY_POSTGRES_UPGRADE_SOURCE_ONLY:-false}" = "true" ] && [ "${BASH_SOURCE[0]}" != "$0" ]; then
    return 0
fi

case "$COMMAND" in
    rollback)
        rollback_postgres
        ;;
    upgrade)
        upgrade_postgres
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage
        fail "Unknown command: ${COMMAND}"
        ;;
esac
