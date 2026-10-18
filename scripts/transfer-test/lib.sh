#!/usr/bin/env bash
# Shared values and helpers of the server transfer test kit. The other scripts source this file.
#
# Source (A): the dev instance of this checkout, as `./scripts/dev` names it. Override: TRANSFER_TEST_SOURCE.
# Target (B): a side-by-side instance of the same checkout. Default "transfer-target". Override: TRANSFER_TEST_TARGET.
# Tokens and the VM SSH key are stored in <main checkout>/.dev-instances/transfer-test/<source>/ (not in git).

set -uo pipefail

TT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
ROOT="$(cd "$TT_DIR/../.." && pwd -P)"
cd "$ROOT"
ENV_DIR="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.dev-instances"

SOURCE="${TRANSFER_TEST_SOURCE:-$(./scripts/dev container | sed 's/^coolify-dev-//')}"
TARGET="${TRANSFER_TEST_TARGET:-transfer-target}"
A="coolify-dev-$SOURCE"
B="coolify-dev-$TARGET"
STATE_DIR="$ENV_DIR/transfer-test/$SOURCE"
VM_KEY="$STATE_DIR/vm_key"

# The VM servers that the tests transfer, and the profile that is localhost of the source.
TRANSFER_PROFILES=(ubuntu-non-root debian-root)
LOCALHOST_PROFILE=ubuntu-root

PRIVILEGED=()
if [[ $EUID -ne 0 ]]; then
  PRIVILEGED=(sudo -n -E)
fi

die() {
  echo "ERROR: $*" >&2
  exit 1
}

env_value() {
  local file="$ENV_DIR/$1.env"
  [[ -f "$file" ]] || die "No dev instance '$1' ($file). See ./scripts/dev urls"
  grep -E "^$2=" "$file" | cut -d= -f2-
}

A_PORT="$(env_value "$SOURCE" APP_PORT)"
A_SLOT="$(env_value "$SOURCE" DEVELOPMENT_QEMU_SLOT)"
A_URL="$(env_value "$SOURCE" APP_URL)"

target_values() {
  B_PORT="$(env_value "$TARGET" APP_PORT)"
  B_URL="$(env_value "$TARGET" APP_URL)"
}
if [[ -f "$ENV_DIR/$TARGET.env" ]]; then
  target_values
fi

# vm_field <profile> <field>: a value of config/development-qemu.php for the source instance (ip, user, uuid).
vm_field() {
  DEVELOPMENT_QEMU_INSTANCE="$SOURCE" DEVELOPMENT_QEMU_SLOT="$A_SLOT" php -r '
    function env($key, $default = null) { $value = getenv($key); return $value === false ? $default : $value; }
    $config = require "config/development-qemu.php";
    echo $config["profiles"][$argv[1]][$argv[2]];
  ' "$1" "$2"
}

running() { [[ "$(docker inspect --format '{{.State.Running}}' "$1" 2>/dev/null)" == true ]]; }

# tinker <container> <php>: run PHP in a Coolify container.
tinker() { docker exec -u www-data "$1" php artisan tinker --execute "$2" 2>/dev/null; }

# php_file <container> <file>: run a PHP file of this kit in a Coolify container.
php_file() {
  docker cp "$2" "$1:/tmp/$(basename "$2")" >/dev/null &&
    docker exec -u www-data "$1" php artisan tinker --execute "require '/tmp/$(basename "$2")';" 2>&1 | grep -v '^$'
}

# api <METHOD> <port> <path> <token> [json]
api() {
  curl -sS -m 30 -X "$1" "http://127.0.0.1:$2/api/v1$3" -H "Authorization: Bearer $4" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' ${5:+-d "$5"}
}

source_token() { cat "$STATE_DIR/source.token"; }
target_token() { cat "$STATE_DIR/target.token"; }

# The VMs accept the dev key that the seeders create as private key 1.
fetch_vm_key() {
  mkdir -p "$STATE_DIR"
  tinker "$A" 'echo optional(App\Models\PrivateKey::find(1))->private_key;' >"$VM_KEY.tmp"
  if grep -q 'PRIVATE KEY' "$VM_KEY.tmp"; then
    mv "$VM_KEY.tmp" "$VM_KEY"
    chmod 600 "$VM_KEY"
    return 0
  fi
  rm -f "$VM_KEY.tmp"
  return 1
}

# ssh_vm <profile> <command>: run a shell command as root on a VM of the source.
ssh_vm() {
  local user ip sudo=''
  user="$(vm_field "$1" user)"
  ip="$(vm_field "$1" ip)"
  [[ "$user" != root ]] && sudo='sudo '
  ssh -i "$VM_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5 \
    "$user@$ip" "${sudo}sh -c $(printf '%q' "$2")"
}

# workloads <profile>: the containers on a VM, without Sentinel, which a claim restarts on purpose.
workloads() { ssh_vm "$1" 'docker ps --format "{{.Names}} {{.ID}}" | grep -v coolify-sentinel | sort'; }

# stable_workloads <profile>: the workloads once they stop changing. reset.sh returns while databases still start.
stable_workloads() {
  local previous='' current
  for _ in $(seq 1 30); do
    current="$(workloads "$1")"
    [[ -n "$current" && "$current" == "$previous" ]] && break
    previous="$current"
    sleep 10
  done
  echo "$current"
}

# require_dev_instance <container>: refuse to wipe anything that is not a running local dev instance.
require_dev_instance() {
  running "$1" || die "Container $1 is not running."
  [[ "$1" == coolify-dev-* ]] || die "$1 is not a scripts/dev instance."
  [[ "$(tinker "$1" 'echo app()->environment();' | tail -1)" == local ]] || die "$1 does not run with APP_ENV=local."
}

# Pass/fail counter of the e2e scripts.
FAILS=0
ok() { echo "  PASS $*"; }
bad() {
  echo "  FAIL $*"
  FAILS=$((FAILS + 1))
}
check() {
  local name="$1"
  shift
  if "$@" >/dev/null 2>&1; then ok "$name"; else bad "$name"; fi
}

login_php="auth()->login(App\\Models\\User::find(0)); session(['currentTeam' => App\\Models\\Team::find(0)]);"

# transfer_from_page <server uuid>: start a transfer A -> B through the Transfer page, wait, print the final status.
transfer_from_page() {
  local status
  tinker "$A" "$login_php Livewire\\Livewire::test(App\\Livewire\\Server\\Transfer::class, ['server_uuid' => '$1'])->set('targetUrl', 'http://localhost:$B_PORT')->set('targetToken', '$(target_token)')->call('migrateServer');" >/dev/null
  for _ in $(seq 1 60); do
    sleep 3
    status="$(tinker "$A" "echo data_get(App\\Models\\Server::where('uuid', '$1')->first()?->server_metadata, 'migration.status');" | tail -1)"
    [[ "$status" != running ]] && break
  done
  echo "$status"
}
