#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

mkdir -p "$TMP_DIR/bin"
printf 'PGDMP test dump' | gzip > "$TMP_DIR/backup.gz"

cat > "$TMP_DIR/bin/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$DOCKER_CALLS"

if [[ "$*" == *"compose -f docker-compose.yml -f docker-compose.dev.yml ps -q postgres"* ]]; then
    echo postgres-container
    exit 0
fi

if [[ "$1" == "exec" && "$*" == *"pg_isready"* ]]; then
    exit 0
fi

if [[ "$1" == "exec" && "$*" == *"pg_restore"* ]]; then
    prefix="$(dd bs=5 count=1 status=none)"
    [[ "$prefix" == "PGDMP" ]]
fi
STUB
chmod +x "$TMP_DIR/bin/docker"

export PATH="$TMP_DIR/bin:$PATH"
export DOCKER_CALLS="$TMP_DIR/docker-calls"

(
    cd "$ROOT"
    scripts/dev-import-database-backup "$TMP_DIR/backup.gz"
)

calls="$(cat "$DOCKER_CALLS")"
[[ "$calls" == *"compose -f docker-compose.yml -f docker-compose.dev.yml up -d postgres"* ]]
[[ "$calls" == *"dropdb --if-exists --force"* ]]
[[ "$calls" == *"createdb -U"* ]]
[[ "$calls" == *"pg_restore --exit-on-error --no-owner --no-privileges"* ]]
[[ "$calls" == *"compose -f docker-compose.yml -f docker-compose.dev.yml stop postgres"* ]]

echo "dev-import-database-backup-test: ok"
