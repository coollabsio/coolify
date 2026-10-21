#!/usr/bin/env bash
# Patches Coolify 4.0 to 4.4 to the security fix release of its own line.
# Only the coolify container is recreated, the compose files and the other containers stay as they are.
# Usage: curl -fsSL https://cdn.coollabs.io/coolify/patch.sh | sudo bash
set -euo pipefail

SOURCE_DIR=/data/coolify/source
ENV_FILE="$SOURCE_DIR/.env"
TIMESTAMP="$(date +%Y-%m-%d-%H-%M-%S)"
LOG_FILE="$SOURCE_DIR/patch-$TIMESTAMP.log"
BACKUP_FILE="$ENV_FILE-$TIMESTAMP"
PATCH_VERSIONS=(4.0.1 4.1.3 4.2.1 4.3.24 4.4.7)

BOLD=$'\033[1m' GREEN=$'\033[32m' BLUE=$'\033[34m' RED=$'\033[31m' RESET=$'\033[0m'

heading() { printf '\n  %s%s%s\n' "$BOLD" "$*" "$RESET"; }
info() { printf '  %sℹ%s %s\n' "$BLUE" "$RESET" "$*"; }
ok() { printf '  %s✔%s %s\n' "$GREEN" "$RESET" "$*"; }
die() {
	printf '  %s✖%s %s\n' "$RED" "$RESET" "$*" >&2
	exit 1
}

main() {
	check_installation
	read_version
	select_patch_version
	check_compose_files
	heading "Patching Coolify $current_version to $patch_version"
	pull_image
	update_env_file
	restart_coolify
	wait_for_coolify
}

check_installation() {
	heading "Checking the installation"
	[ "$EUID" = 0 ] || die "run this script as root, for example with sudo"
	[ -f "$ENV_FILE" ] && [ -f "$SOURCE_DIR/docker-compose.prod.yml" ] || die "Coolify is not installed in $SOURCE_DIR"
	docker compose version > /dev/null 2>&1 || die "the Docker Compose plugin is not installed"
	compose=(docker compose --env-file "$ENV_FILE" -f "$SOURCE_DIR/docker-compose.yml" -f "$SOURCE_DIR/docker-compose.prod.yml")
	if [ -f "$SOURCE_DIR/docker-compose.custom.yml" ]; then
		compose+=(-f "$SOURCE_DIR/docker-compose.custom.yml")
	fi
	if [ -f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml" ]; then
		compose+=(-f "$SOURCE_DIR/docker-compose.postgres-upgrade.yml")
	fi
	ok "Coolify is installed in $SOURCE_DIR"
}

read_version() {
	current_version="$(docker exec -w /var/www/html coolify php bootstrap/getVersion.php 2> /dev/null)" || die "the coolify container is not running"
	ok "Coolify $BOLD$current_version$RESET is running"
}

select_patch_version() {
	[[ "$current_version" =~ ^4\.([0-4])\.([0-9]+)$ ]] || die "Coolify $current_version has no security fix release, upgrade to the latest version from the dashboard instead"
	patch_version="${PATCH_VERSIONS[${BASH_REMATCH[1]}]}"
	if [ "${BASH_REMATCH[2]}" -ge "${patch_version##*.}" ]; then
		ok "Coolify $current_version already includes the security fixes"
		exit 0
	fi
}

check_compose_files() {
	local has_realtime=false needs_realtime=true
	if grep -q 'coollabsio/coolify-realtime:' "$SOURCE_DIR/docker-compose.prod.yml"; then
		has_realtime=true
	fi
	if [[ "$patch_version" == 4.4.* ]]; then
		needs_realtime=false
	fi
	[ "$has_realtime" = "$needs_realtime" ] || die "the compose files in $SOURCE_DIR do not belong to Coolify $current_version, upgrade to the latest version from the dashboard instead"
	ok "the compose files belong to Coolify $current_version"
}

pull_image() {
	LATEST_IMAGE="$patch_version" "${compose[@]}" pull coolify < /dev/null >> "$LOG_FILE" 2>&1 || die "could not pull Coolify $patch_version, nothing was changed (log: $LOG_FILE)"
	ok "pulled Coolify $patch_version"
}

update_env_file() {
	local key updated
	cp "$ENV_FILE" "$BACKUP_FILE"
	for key in LATEST_IMAGE COOLIFY_VERSION; do
		if grep -q "^$key=" "$ENV_FILE"; then
			updated="$(sed "s|^$key=.*|$key=$patch_version|" "$ENV_FILE")"
			printf '%s\n' "$updated" > "$ENV_FILE"
		fi
	done
	ok "backed up .env to $BACKUP_FILE"
}

restart_coolify() {
	# Detached, because the Coolify 4.4 terminal runs inside the coolify container and closes when it stops
	LATEST_IMAGE="$patch_version" nohup "${compose[@]}" up -d --no-deps coolify < /dev/null >> "$LOG_FILE" 2>&1 &
	wait $! || die "could not restart the coolify container (log: $LOG_FILE)"
	ok "restarted the coolify container"
}

wait_for_coolify() {
	local status
	for _ in $(seq 60); do
		status="$(docker inspect --format '{{.State.Health.Status}}' coolify 2> /dev/null || true)"
		if [ "$status" = healthy ]; then
			ok "Coolify $BOLD$patch_version$RESET is healthy and runs PHP $(docker exec coolify php -r 'echo PHP_VERSION;')"
			return
		fi
		sleep 5
	done
	info "roll back with: cp $BACKUP_FILE $ENV_FILE && LATEST_IMAGE=$current_version ${compose[*]} up -d --no-deps coolify"
	die "Coolify $patch_version is not healthy after 5 minutes, check it with: docker logs coolify"
}

main
