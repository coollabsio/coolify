#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

read_coolify_env() {
  key="$1"
  default_value="$2"
  current_value="${!key:-}"

  if [ -n "$current_value" ]; then
    printf '%s\n' "$current_value"
    return
  fi

  if [ -f .env ]; then
    env_value="$(grep -E "^${key}=" .env 2>/dev/null | tail -n1 | cut -d= -f2- | sed "s/^['\"]//; s/['\"]$//")"
    if [ -n "$env_value" ]; then
      printf '%s\n' "$env_value"
      return
    fi
  fi

  printf '%s\n' "$default_value"
}

coold_vm_count() {
  local count
  count="$(read_coolify_env COOLIFY_COOLD_VM_COUNT 2)"

  if [ "$count" != "1" ] && [ "$count" != "2" ]; then
    echo "ERROR: COOLIFY_COOLD_VM_COUNT supports 1 or 2 for now." >&2
    exit 1
  fi

  printf '%s\n' "$count"
}

coold_vm_instance() {
  local index="$1"
  local base
  base="$(read_coolify_env COOLIFY_COOLD_LIMA_INSTANCE coold-dev)"

  if [ "$index" = "1" ]; then
    printf '%s\n' "$base"
    return
  fi

  read_coolify_env "COOLIFY_COOLD_LIMA_INSTANCE_${index}" "${base}-${index}"
}

coold_vm_wg_ip() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_WG_IP_${index}" "100.64.0.${index}"
}

coold_vm_wg_port() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_WG_PORT_${index}" "$((51820 + index))"
}

coold_vm_dns_name() {
  local index="$1"
  printf '%s.local\n' "$(coold_vm_instance "$index")"
}

coold_asset_arch() {
  case "$(uname -m)" in
    arm64|aarch64)
      printf 'arm64\n'
      ;;
    x86_64|amd64)
      printf 'amd64\n'
      ;;
    *)
      echo "ERROR: Unsupported coold asset architecture: $(uname -m)" >&2
      return 1
      ;;
  esac
}

resolve_coold_asset_checksum() {
  local version="$1"
  local asset="$2"
  local arch
  local url

  arch="$(coold_asset_arch)"
  url="https://github.com/coollabsio/coold/releases/download/${version}/${asset}-linux-musl-${arch}.tar.gz.sha256"

  curl -fsSL --retry 3 --max-time 30 "$url" | awk '{ print $1; exit }'
}

prepare_coold_asset_cache_bust() {
  local flux_version
  local cli_version
  local cache_dir="$ROOT/.dev/coold-assets"
  local cache_file="$cache_dir/docker-cache-key"
  local previous_key=""
  local current_key
  local resolved_checksum

  flux_version="$(read_coolify_env COOLIFY_FLUX_VERSION nightly)"
  cli_version="$(read_coolify_env COOLIFY_CLI_VERSION nightly)"

  if [ -z "${COOLIFY_FLUX_CHECKSUM:-}" ]; then
    if resolved_checksum="$(resolve_coold_asset_checksum "$flux_version" flux 2>/dev/null)"; then
      export COOLIFY_FLUX_CHECKSUM="$resolved_checksum"
    else
      echo "WARN: Could not resolve Flux checksum for ${flux_version}; Docker may reuse a cached Flux layer." >&2
      export COOLIFY_FLUX_CHECKSUM=unknown
    fi
  fi

  if [ -z "${COOLIFY_CLI_CHECKSUM:-}" ]; then
    if resolved_checksum="$(resolve_coold_asset_checksum "$cli_version" coolify 2>/dev/null)"; then
      export COOLIFY_CLI_CHECKSUM="$resolved_checksum"
    else
      echo "WARN: Could not resolve Coolify CLI checksum for ${cli_version}; Docker may reuse a cached CLI layer." >&2
      export COOLIFY_CLI_CHECKSUM=unknown
    fi
  fi

  current_key="flux=${flux_version}:${COOLIFY_FLUX_CHECKSUM};cli=${cli_version}:${COOLIFY_CLI_CHECKSUM}"

  if [ -f "$cache_file" ]; then
    previous_key="$(cat "$cache_file")"
  fi

  mkdir -p "$cache_dir"
  printf '%s\n' "$current_key" > "$cache_file"

  if [ "$previous_key" != "$current_key" ]; then
    COOLD_ASSETS_CHANGED=changed
    return
  fi

  COOLD_ASSETS_CHANGED=unchanged
}

resolve_lima_dns_name() {
  local name="$1"
  local ip=""

  if command -v dscacheutil >/dev/null 2>&1; then
    ip="$(dscacheutil -q host -a name "$name" 2>/dev/null | awk '/ip_address:/ && $2 ~ /^[0-9.]+$/ { print $2; exit }')"
  fi

  if [ -z "$ip" ] && command -v getent >/dev/null 2>&1; then
    ip="$(getent ahostsv4 "$name" 2>/dev/null | awk '{ print $1; exit }')"
  fi

  if [ -z "$ip" ]; then
    ip="$(ping -c 1 -W 1 "$name" 2>/dev/null | sed -n 's/^PING .* (\([0-9.]*\)).*$/\1/p' | head -n 1)"
  fi

  if [ -z "$ip" ]; then
    echo "ERROR: Could not resolve ${name} from the host." >&2
    echo "Check that the Lima VM is running, bridged networking is enabled, and Avahi/mDNS finished provisioning." >&2
    return 1
  fi

  printf '%s\n' "$ip"
}

sync_lima_hosts_into_coolify_container() {
  local count
  local hosts_file="$ROOT/.dev/lima/hosts"
  local name
  local ip

  count="$(coold_vm_count)"
  mkdir -p "$(dirname "$hosts_file")"
  : > "$hosts_file"

  for index in $(seq 1 "$count"); do
    name="$(coold_vm_dns_name "$index")"
    ip="$(resolve_lima_dns_name "$name")" || return 1
    printf '%s %s\n' "$ip" "$name" >> "$hosts_file"
  done

  echo "==> Syncing Lima .local host records into the Coolify container..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T -u root coolify sh -lc '
set -e
records=/tmp/coolify-lima-hosts
next=/tmp/coolify-hosts-next
cat > "$records"
cp /etc/hosts "$next"
while read -r ip name; do
  if [ -z "$ip" ] || [ -z "$name" ]; then
    continue
  fi

  awk -v name="$name" '\''$0 !~ ("(^|[[:space:]])" name "([[:space:]]|$)") { print }'\'' "$next" > "${next}.filtered"
  printf "%s %s\n" "$ip" "$name" >> "${next}.filtered"
  mv "${next}.filtered" "$next"
done < "$records"
cat "$next" > /etc/hosts
rm -f "$records" "$next" "${next}.filtered"
' < "$hosts_file"
}

coold_vm_container_subnet() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_CONTAINER_SUBNET_${index}" "10.210.$((index - 1)).0/24"
}

coold_vm_container_gateway() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_CONTAINER_GATEWAY_${index}" "10.210.$((index - 1)).1"
}

coolify_cli_bin() {
  printf '%s\n' '/usr/local/bin/coolify'
}

lima_ssh_target() {
  local index="$1"
  coold_vm_dns_name "$index"
}

coolify_nodes_arg() {
  local count
  local nodes=""
  local node
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    node="$(lima_ssh_target "$index")" || return 1
    if [ -n "$nodes" ]; then
      nodes="${nodes},"
    fi
    nodes="${nodes}${node}"
  done

  printf '%s\n' "$nodes"
}

coolify_wg_listen_overrides_arg() {
  local count
  local overrides=""
  local node
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    node="$(lima_ssh_target "$index")"
    if [ -n "$overrides" ]; then
      overrides="${overrides},"
    fi
    overrides="${overrides}${node}=$(coold_vm_wg_port "$index")"
  done

  printf '%s\n' "$overrides"
}

coolify_wg_endpoint_overrides_arg() {
  local count
  local overrides=""
  local node
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    node="$(lima_ssh_target "$index")"
    if [ -n "$overrides" ]; then
      overrides="${overrides},"
    fi
    overrides="${overrides}${node}=$(coold_vm_dns_name "$index"):$(coold_vm_wg_port "$index")"
  done

  printf '%s\n' "$overrides"
}

coolify_ssh_key() {
  read_coolify_env COOLIFY_CLI_SSH_KEY "/var/www/html/.dev/lima/ssh_key"
}

coolify_host_ssh_key_source() {
  read_coolify_env COOLIFY_CLI_HOST_SSH_KEY "$HOME/.lima/_config/user"
}

ensure_coolify_container_ssh_key() {
  local source_key
  local target_key="$ROOT/.dev/lima/ssh_key"

  source_key="$(coolify_host_ssh_key_source)"
  if [ ! -f "$source_key" ]; then
    echo "ERROR: SSH key for dev Lima VMs was not found at ${source_key}." >&2
    echo "Set COOLIFY_CLI_HOST_SSH_KEY to the host-side private key that Lima authorized." >&2
    exit 1
  fi

  mkdir -p "$(dirname "$target_key")"
  install -m 0600 "$source_key" "$target_key"
}

coolify_ssh_user() {
  read_coolify_env COOLIFY_CLI_SSH_USER coolify
}

coolify_bootstrap_concurrency() {
  read_coolify_env COOLIFY_COOLD_BOOTSTRAP_CONCURRENCY 1
}

coolify_bootstrap_ssh_timeout() {
  read_coolify_env COOLIFY_COOLD_BOOTSTRAP_SSH_TIMEOUT 90s
}

coolify_bootstrap_command() {
  local nodes
  local listen_overrides
  local endpoint_overrides

  nodes="$(coolify_nodes_arg)" || return 1
  listen_overrides="$(coolify_wg_listen_overrides_arg)" || return 1
  endpoint_overrides="$(coolify_wg_endpoint_overrides_arg)" || return 1

  cat <<CMD
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify $(coolify_cli_bin) init bootstrap \\
  --nodes "${nodes}" \\
  --ssh-key "$(coolify_ssh_key)" \\
  --ssh-user "$(coolify_ssh_user)" \\
  --concurrency "$(coolify_bootstrap_concurrency)" \\
  --ssh-timeout "$(coolify_bootstrap_ssh_timeout)" \\
  --debug \\
  --wg-listen-port-overrides "${listen_overrides}" \\
  --wg-endpoint-overrides "${endpoint_overrides}" \\
  --coold-version "$(read_coolify_env COOLIFY_COOLD_VERSION nightly)" \\
  --corrosion-version "$(read_coolify_env COOLIFY_CORROSION_VERSION v1.0.0)" \\
  --yes
CMD
}

coolify_bootstrap() {
  local nodes
  local listen_overrides
  local endpoint_overrides
  ensure_coolify_container_ssh_key

  nodes="$(coolify_nodes_arg)" || return 1
  listen_overrides="$(coolify_wg_listen_overrides_arg)" || return 1
  endpoint_overrides="$(coolify_wg_endpoint_overrides_arg)" || return 1

  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify "$(coolify_cli_bin)" init bootstrap \
    --nodes "$nodes" \
    --ssh-key "$(coolify_ssh_key)" \
    --ssh-user "$(coolify_ssh_user)" \
    --concurrency "$(coolify_bootstrap_concurrency)" \
    --ssh-timeout "$(coolify_bootstrap_ssh_timeout)" \
    --debug \
    --wg-listen-port-overrides "$listen_overrides" \
    --wg-endpoint-overrides "$endpoint_overrides" \
    --coold-version "$(read_coolify_env COOLIFY_COOLD_VERSION nightly)" \
    --corrosion-version "$(read_coolify_env COOLIFY_CORROSION_VERSION v1.0.0)" \
    --yes
}

diagnose_coold_bootstrap_failure() {
  local count
  local instance
  count="$(coold_vm_count)"

  echo "==> Bootstrap failed; collecting quick VM diagnostics..." >&2
  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    echo "--- ${instance}: diagnostics ---" >&2
    run_with_timeout 45 env COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell <<'SH' >&2 || true
set +e
echo '[cloud-init]'
cloud-init status 2>/dev/null || true
echo '[systemd]'
systemctl is-system-running 2>/dev/null || true
echo '[apt/dpkg locks]'
for lock in /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock; do
  fuser "$lock" 2>/dev/null && echo "busy: $lock" || true
done
echo '[failed units]'
systemctl --failed --no-pager 2>/dev/null || true
echo '[coolify services]'
systemctl --no-pager --full status wg-quick@wg0 podman.socket corrosion coold 2>/dev/null | tail -n 120 || true
echo '[disk]'
df -h / /var 2>/dev/null || true
SH
  done
}

coolify_bootstrap_with_retry() {
  local attempt
  local attempts=5

  for attempt in $(seq 1 "$attempts"); do
    if coolify_bootstrap; then
      return
    fi

    diagnose_coold_bootstrap_failure

    if [ "$attempt" = "$attempts" ]; then
      return 1
    fi

    echo "WARN: coolify bootstrap failed on attempt ${attempt}/${attempts}; retrying because fresh Lima hosts can finish setup after partial bootstrap phases..." >&2
    sleep 3
  done
}

coolify_dev() {
  local command="${1:-help}"
  if [ $# -gt 0 ]; then
    shift
  fi

  case "$command" in
    install)
      echo "==> coolify CLI is provided by the Coolify dev container."
      docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify "$(coolify_cli_bin)" --version
      ;;
    path)
      coolify_cli_bin
      ;;
    bootstrap-command)
      coolify_bootstrap_command
      ;;
    run)
      exec docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify "$(coolify_cli_bin)" "$@"
      ;;
    -h|--help|help)
      cat <<'USAGE'
Usage: scripts/dev.sh coolify <command>

Commands:
  install             Print the coolify CLI version from the dev container
  path                Print the coolify CLI path inside the dev container
  bootstrap-command   Print the dev Lima bootstrap command without running it
  run <args>          Run coolify with arbitrary args

Example:
  scripts/dev.sh coolify bootstrap-command
USAGE
      ;;
    *)
      echo "unknown coolify command: $command" >&2
      echo "Run: scripts/dev.sh coolify help" >&2
      exit 1
      ;;
  esac
}

coold_vm() {
  local index="$1"
  shift
  COOLIFY_COOLD_LIMA_INSTANCE="$(coold_vm_instance "$index")" \
  COOLIFY_COOLD_VM_WG_IP="$(coold_vm_wg_ip "$index")" \
  COOLIFY_COOLD_VM_CONTAINER_SUBNET="$(coold_vm_container_subnet "$index")" \
  COOLIFY_COOLD_VM_CONTAINER_GATEWAY="$(coold_vm_container_gateway "$index")" \
    scripts/coold-vm.sh "$@"
}

coold_vm_shell() {
  local instance="${1:-}"

  if [ -z "$instance" ]; then
    instance="$(coold_vm_instance 1)"
  fi

  if [[ "$instance" =~ ^[0-9]+$ ]]; then
    echo "ERROR: Use the Lima hostname, not a numeric VM index." >&2
    echo "Example: scripts/dev.sh shell $(coold_vm_instance 1)" >&2
    exit 1
  fi

  COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell
}

coold_vm_up_with_retry() {
  local index="$1"
  local attempt
  local attempts=2
  local instance

  instance="$(coold_vm_instance "$index")"

  for attempt in $(seq 1 "$attempts"); do
    if coold_vm "$index" up; then
      return
    fi

    if [ "$attempt" = "$attempts" ]; then
      echo "ERROR: ${instance} did not become ready after ${attempts} attempts." >&2
      return 1
    fi

    echo "WARN: ${instance} did not become ready; deleting and retrying with a fresh Lima instance..." >&2
    limactl stop --force --tty=false "$instance" >/dev/null 2>&1 || true
    cleanup_lima_instance_processes "$instance"
    limactl delete --force --tty=false "$instance" >/dev/null 2>&1 || true
    cleanup_lima_instance_processes "$instance"
  done
}

mint_host_jwt_for_host() {
  local host_id="$1"
  local attempts=60
  local output

  for attempt in $(seq 1 "$attempts"); do
    if output="$(docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan flux:dev "$host_id" 2>&1)"; then
      printf '%s\n' "$output" | tail -n 1
      return 0
    fi

    echo "==> Waiting for Flux dev JWT key for ${host_id} (${attempt}/${attempts})..." >&2
    printf '%s\n' "$output" | tail -n 3 | sed 's/^/[coolify] /' >&2
    sleep 2
  done

  echo "ERROR: Could not mint Flux dev host JWT for ${host_id}." >&2
  return 1
}

follow_logs() {
  local coold_vm_enabled="$1"
  local count
  local vm_logs_pids=""
  count="$(coold_vm_count)"

  echo "==> Following dev logs. Press Ctrl-C to stop the dev environment."

  cleanup_logs() {
    for pid in $vm_logs_pids; do
      kill "$pid" >/dev/null 2>&1 || true
    done
  }

  stop_from_signal() {
    trap - INT TERM EXIT
    cleanup_logs
    echo
    echo "==> Ctrl-C received; stopping dev environment..."
    down
    exit 130
  }

  trap cleanup_logs EXIT
  trap stop_from_signal INT TERM

  if [ "$coold_vm_enabled" != "false" ]; then
    for index in $(seq 1 "$count"); do
      instance="$(coold_vm_instance "$index")"
      coold_vm "$index" logs-agent | sed "s/^/[${instance}] /" &
      vm_logs_pids="$vm_logs_pids $!"
    done
  fi

  docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f
}

sync_v5_dev_lima_servers() {
  local count
  local index
  local instance
  local server_args=()
  local ssh_port
  local ssh_user

  count="$(coold_vm_count)"
  ssh_user="$(coolify_ssh_user)"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    server_args+=(--server="${instance}|$(coold_vm_dns_name "$index")|${ssh_user}|22|$(coold_vm_wg_ip "$index")")
  done

  echo "==> Running pending migrations before syncing v5 dev Lima state..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan migrate --force

  echo "==> Seeding dev Lima VM(s) into v5 clusters/servers..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T \
    -e COOLIFY_CLI_SSH_USER="$ssh_user" \
    coolify php artisan v5:sync-dev-lima-servers \
      "${server_args[@]}"
}

configure_flux_dev_for_vm() {
  local index="$1"
  local host_id
  local host_jwt
  local flux_url

  host_id="$(coold_vm_wg_ip "$index")"
  flux_url="$(read_coolify_env COOLIFY_COOLD_VM_FLUX_URL http://host.lima.internal:6443)"

  echo "==> Minting Flux dev host JWT for ${host_id}..."
  host_jwt="$(mint_host_jwt_for_host "$host_id")"
  echo "==> Installing Flux dev env/JWT into $(coold_vm_instance "$index")..."
  coold_vm "$index" install-host-jwt "$host_jwt"

  COOLIFY_COOLD_LIMA_INSTANCE="$(coold_vm_instance "$index")" scripts/coold-vm.sh shell <<SH
set -e
sudo install -d -m 0755 /etc/systemd/system/coold.service.d
sudo tee /etc/systemd/system/coold.service.d/10-flux-dev.conf >/dev/null <<UNIT
[Service]
Environment=COOLIFY_COOLD_FLUX_URL=${flux_url}
Environment=COOLIFY_COOLD_HOST_JWT_PATH=/etc/coolify/host-jwt
UNIT
sudo systemctl daemon-reload
sudo systemctl restart coold.service
SH
}

up() {
  local coold_vm_enabled
  local follow_dev_logs
  local count
  local naked=false
  local compose_args=()
  coold_vm_enabled="$(read_coolify_env COOLIFY_COOLD_VM_ENABLED true)"
  follow_dev_logs="$(read_coolify_env COOLIFY_DEV_FOLLOW_LOGS true)"
  count="$(coold_vm_count)"

  while [ $# -gt 0 ]; do
    case "$1" in
      --naked)
        naked=true
        shift
        ;;
      *)
        compose_args+=("$1")
        shift
        ;;
    esac
  done

  if [ "$coold_vm_enabled" != "false" ]; then
    echo "==> Starting ${count} Coolify coold VM(s) before Docker Compose..."
    for index in $(seq 1 "$count"); do
      coold_vm_up_with_retry "$index"
    done
  else
    echo "==> COOLIFY_COOLD_VM_ENABLED=false; skipping coold VM."
  fi

  echo "==> Starting Coolify Docker stack with Docker Compose..."
  prepare_coold_asset_cache_bust
  if [ "$COOLD_ASSETS_CHANGED" = "changed" ]; then
    echo "==> Coold nightly assets changed; rebuilding the Coolify dev image..."
    COOLIFY_CLI_SSH_USER="$(coolify_ssh_user)" docker compose -f docker-compose.yml -f docker-compose.dev.yml build coolify
  fi

  if [ "${#compose_args[@]}" -gt 0 ]; then
    COOLIFY_CLI_SSH_USER="$(coolify_ssh_user)" docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d "${compose_args[@]}"
  else
    COOLIFY_CLI_SSH_USER="$(coolify_ssh_user)" docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
  fi

  if [ "$coold_vm_enabled" != "false" ]; then
    sync_lima_hosts_into_coolify_container
  fi

  if [ "$naked" = "true" ]; then
    echo "==> --naked enabled. Skipping coolify bootstrap and Flux VM wiring. Use /v5 to bootstrap hosts from the UI."
    return
  fi

  if [ "$coold_vm_enabled" != "false" ]; then
    echo "==> Bootstrapping coold VM mesh with coolify..."
    coolify_bootstrap_with_retry

    for index in $(seq 1 "$count"); do
      configure_flux_dev_for_vm "$index"
    done

    sync_v5_dev_lima_servers
  fi

  if [ "$follow_dev_logs" = "false" ]; then
    echo "==> Dev environment is ready. Use 'docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f' or 'scripts/coold-vm.sh logs-agent' to follow logs."
    return
  fi

  follow_logs "$coold_vm_enabled"
}

down() {
  local coold_vm_enabled
  local stop_coold_vm
  local cleanup=false
  local compose_args=()
  coold_vm_enabled="$(read_coolify_env COOLIFY_COOLD_VM_ENABLED true)"
  stop_coold_vm="$(read_coolify_env COOLIFY_COOLD_VM_STOP_ON_DOWN false)"

  while [ $# -gt 0 ]; do
    case "$1" in
      --cleanup)
        cleanup=true
        shift
        ;;
      *)
        compose_args+=("$1")
        shift
        ;;
    esac
  done

  if [ "$coold_vm_enabled" != "false" ]; then
    for index in $(seq 1 "$(coold_vm_count)"); do
      echo "==> Stopping coold VM agent service on $(coold_vm_instance "$index")..."
      coold_vm "$index" stop-agent || true
    done
  fi

  echo "==> Stopping Coolify Docker stack with Docker Compose..."
  if [ "${#compose_args[@]}" -gt 0 ]; then
    docker compose -f docker-compose.yml -f docker-compose.dev.yml down "${compose_args[@]}"
  else
    docker compose -f docker-compose.yml -f docker-compose.dev.yml down
  fi

  if [ "$cleanup" = "true" ]; then
    clean_vms
    return
  fi

  if [ "$stop_coold_vm" = "true" ]; then
    echo "==> Stopping Coolify coold VM..."
    for index in $(seq 1 "$(coold_vm_count)"); do
      coold_vm "$index" stop
    done
  fi
}

kill_matching_processes() {
  local pattern="$1"
  local pids

  pids="$(pgrep -f "$pattern" 2>/dev/null || true)"
  if [ -z "$pids" ]; then
    return
  fi

  kill $pids >/dev/null 2>&1 || true
  sleep 1
  kill -9 $pids >/dev/null 2>&1 || true
}

cleanup_lima_instance_processes() {
  local instance="$1"

  kill_matching_processes "limactl hostagent .*${instance}"
  kill_matching_processes "ssh: .*/.lima/${instance}/ssh.sock"
  kill_matching_processes "ssh .*ControlPath=.*${instance}/ssh.sock"
  rm -f "$HOME/.lima/${instance}/ssh.sock" "$HOME/.lima/${instance}/ha.sock"
}

clean_vms() {
  local count
  count="$(coold_vm_count)"

  echo "==> Deleting ${count} coold Lima VM(s). This removes VM-local state."
  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    echo "==> Deleting ${instance}..."
    limactl stop --force --tty=false "$instance" >/dev/null 2>&1 || true
    cleanup_lima_instance_processes "$instance"
    if ! run_with_timeout 60 limactl delete --force --tty=false "$instance"; then
      echo "WARN: limactl delete timed out for ${instance}; killing matching limactl clients." >&2
      pkill -f "limactl.*${instance}" >/dev/null 2>&1 || true
      rm -rf "$HOME/.lima/${instance}"
    fi
    cleanup_lima_instance_processes "$instance"
  done
}

run_with_timeout() {
  local timeout_seconds="$1"
  shift
  local pid
  local elapsed=0

  "$@" &
  pid="$!"

  while kill -0 "$pid" >/dev/null 2>&1; do
    if [ "$elapsed" -ge "$timeout_seconds" ]; then
      kill "$pid" >/dev/null 2>&1 || true
      sleep 2
      kill -9 "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
      return 124
    fi

    sleep 1
    elapsed=$((elapsed + 1))
  done

  wait "$pid"
}

corrosion_for_each_vm() {
  local label="$1"
  shift
  local count
  local script
  count="$(coold_vm_count)"
  script="$(cat)"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    echo "--- ${instance}: ${label} ---"
    printf '%s\n' "$script" | COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell "$@"
  done
}

corrosion_check() {
  local count
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    peer_index=1
    if [ "$index" = "1" ]; then
      peer_index=2
    fi
    peer_ip="$(coold_vm_wg_ip "$peer_index")"

    echo "--- ${instance}: check ---"
    COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell <<SH
set -e
printf 'services: '
systemctl is-active corrosion.service || true
printf 'coold: '
systemctl is-active coold.service || true
printf 'wireguard: '
sudo wg show wg0 >/dev/null 2>&1 && echo active || echo unavailable
printf 'peer ping (${peer_ip}): '
ping -c 1 -W 2 ${peer_ip} >/dev/null 2>&1 && echo ok || echo failed
printf 'gossip: '
awk '/^\[gossip\]/{section=1; next} /^\[/{section=0} section && /^addr =|^bootstrap =/ {printf "%s ", \$0} END {print ""}' /etc/corrosion/config.toml
printf 'registered containers: '
sudo sqlite3 /var/lib/corrosion/corrosion.db 'select count(*) from service_endpoints;' 2>/dev/null || echo unavailable
SH
  done
}

corrosion_containers() {
  corrosion_for_each_vm containers <<'SH'
echo '[corrosion service_endpoints]'
sudo sqlite3 -header -column /var/lib/corrosion/corrosion.db \
  'select container_id, container_name, namespace, host_mgmt_ip, container_ip, state, health, updated_at from service_endpoints order by updated_at desc;' 2>/dev/null \
  || echo 'service_endpoints table unavailable'

echo
echo '[rootful podman]'
sudo podman ps --format 'table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null \
  || echo 'rootful podman unavailable'

echo
echo '[rootless podman]'
podman ps --format 'table {{.ID}}\t{{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null \
  || echo 'rootless podman unavailable'
SH
}

corrosion_config() {
  corrosion_for_each_vm config <<'SH'
sudo cat /etc/corrosion/config.toml
SH
}

corrosion_logs() {
  local index="${1:-1}"
  coold_vm "$index" logs-agent
}

corrosion_sql() {
  local query="$*"

  if [ -z "$query" ]; then
    echo "Usage: scripts/dev.sh corrosion sql <query>" >&2
    exit 1
  fi

  corrosion_for_each_vm sql <<SH
sudo sqlite3 -header -column /var/lib/corrosion/corrosion.db $(printf '%q' "$query")
SH
}

corrosion() {
  local command="${1:-help}"
  if [ $# -gt 0 ]; then
    shift
  fi

  case "$command" in
    check)
      corrosion_check
      ;;
    containers|registered-containers)
      corrosion_containers
      ;;
    config)
      corrosion_config
      ;;
    logs)
      corrosion_logs "${1:-1}"
      ;;
    sql)
      corrosion_sql "$@"
      ;;
    -h|--help|help)
      cat <<'USAGE'
Usage: scripts/dev.sh corrosion <command>

Commands:
  check                 Show Corrosion service, WireGuard, gossip, and row-count state
  containers            List registered service_endpoints rows on each VM
  config                Print /etc/corrosion/config.toml from each VM
  logs [n]              Follow coold/corrosion logs for VM n (default: 1)
  sql <query>           Run a read-only sqlite query against each Corrosion DB
USAGE
      ;;
    *)
      echo "unknown corrosion command: $command" >&2
      echo "Run: scripts/dev.sh corrosion help" >&2
      exit 1
      ;;
  esac
}

example_nginx_name() {
  local index="$1"

  if [ "$index" = "1" ]; then
    printf '%s\n' coolify-example-nginx
    return
  fi

  printf 'coolify-example-nginx-%s\n' "$index"
}

example_nginx_up() {
  local count
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    name="$(example_nginx_name "$index")"
    gateway="$(coold_vm_container_gateway "$index")"

    echo "--- ${instance}: starting ${name} with coold DNS (${gateway}) ---"
    COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell <<SH
set -e
sudo podman rm -f ${name} >/dev/null 2>&1 || true
sudo podman run -d \\
  --name ${name} \\
  --network coolify-default-mesh \\
  --dns ${gateway} \\
  --dns-search default.coolify.internal \\
  docker.io/library/nginx:alpine
SH
  done
}

example_nginx_down() {
  local count
  count="$(coold_vm_count)"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    name="$(example_nginx_name "$index")"

    echo "--- ${instance}: removing ${name} ---"
    COOLIFY_COOLD_LIMA_INSTANCE="$instance" scripts/coold-vm.sh shell <<SH
sudo podman rm -f ${name} >/dev/null 2>&1 || true
SH
  done
}

example_nginx_check_dns() {
  COOLIFY_COOLD_LIMA_INSTANCE="$(coold_vm_instance 1)" scripts/coold-vm.sh shell <<'SH'
set -e
echo '--- resolv.conf ---'
sudo podman exec coolify-example-nginx cat /etc/resolv.conf
echo
echo '--- coold DNS lookup through search domain ---'
sudo podman exec coolify-example-nginx nslookup coolify-example-nginx-2
echo
echo '--- coold DNS lookup by full name ---'
sudo podman exec coolify-example-nginx nslookup coolify-example-nginx-2.default.coolify.internal
SH
}

example_nginx_require_pair() {
  if [ "$(coold_vm_count)" = "1" ]; then
    echo "ERROR: example-nginx ping command requires COOLIFY_COOLD_VM_COUNT=2." >&2
    exit 1
  fi
}

example_nginx_container_ip() {
  local index="$1"
  local name
  name="$(example_nginx_name "$index")"

  COOLIFY_COOLD_LIMA_INSTANCE="$(coold_vm_instance "$index")" scripts/coold-vm.sh shell <<SH
set -e
sudo podman inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ${name}
SH
}

example_nginx_ping() {
  example_nginx_require_pair

  COOLIFY_COOLD_LIMA_INSTANCE="$(coold_vm_instance 1)" scripts/coold-vm.sh shell <<'SH'
set -e
sudo podman exec coolify-example-nginx wget -qO- --timeout=3 http://coolify-example-nginx-2.default.coolify.internal/ >/dev/null
echo 'ok: coolify-example-nginx can reach coolify-example-nginx-2 on tcp/80'
SH
}

example_nginx_help() {
  cat <<'USAGE'
Usage: scripts/dev.sh example-nginx <command>

Commands:
  up             Start one nginx container on each coold VM with coold DNS configured
  down           Remove the example nginx containers
  check-dns      Verify host 1 nginx can resolve host 2 nginx through coold DNS
  ping           Verify host 1 nginx can reach host 2 nginx on tcp/80
USAGE
}

example_nginx() {
  local command="${1:-help}"
  if [ $# -gt 0 ]; then
    shift
  fi

  case "$command" in
    up)
      example_nginx_up
      ;;
    down)
      example_nginx_down
      ;;
    check-dns)
      example_nginx_check_dns
      ;;
    ping)
      example_nginx_ping
      ;;
    -h|--help|help)
      example_nginx_help
      ;;
    *)
      echo "unknown example-nginx command: $command" >&2
      echo "Run: scripts/dev.sh example-nginx help" >&2
      exit 1
      ;;
  esac
}


refresh_test_host_key() {
  echo "==> Refreshing /tmp/testhostkey inside coolify..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan tinker --execute='file_put_contents("/tmp/testhostkey", \App\Models\PrivateKey::query()->where("name", "Testing Host Key")->sole()->private_key); chmod("/tmp/testhostkey", 0600);'
}

recreate_naked_lima_vm() {
  local instance="${1:-coolify-naked-test}"
  local config="${2:-.dev/lima/coolify-naked-test.yaml}"
  local attempt
  local attempts=2
  local start_timeout

  start_timeout="$(read_coolify_env COOLIFY_NAKED_VM_START_TIMEOUT "$(read_coolify_env COOLIFY_COOLD_VM_START_TIMEOUT 300)")"

  if [ ! -f "$config" ]; then
    echo "ERROR: naked Lima config not found: ${config}" >&2
    exit 1
  fi

  echo "==> Recreating naked Lima VM: ${instance}..."
  for attempt in $(seq 1 "$attempts"); do
    limactl stop --force --tty=false "$instance" >/dev/null 2>&1 || true
    cleanup_lima_instance_processes "$instance"
    limactl delete --force --tty=false "$instance" >/dev/null 2>&1 || true
    cleanup_lima_instance_processes "$instance"

    if run_with_timeout "$start_timeout" limactl start --tty=false --name="$instance" "$config"; then
      return
    fi

    echo "WARN: ${instance} did not become ready on attempt ${attempt}/${attempts}; deleting and retrying..." >&2
  done

  echo "ERROR: ${instance} did not become ready after ${attempts} attempts." >&2
  return 1
}

fresh() {
  echo "==> Recreating coold Lima VMs and Coolify dev stack..."
  down --cleanup
  COOLIFY_DEV_FOLLOW_LOGS=false up

  echo "==> Refreshing Coolify database with seed data..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan migrate:fresh --seed --force

  if [ "$(read_coolify_env COOLIFY_COOLD_VM_ENABLED true)" != "false" ]; then
    echo "==> Re-syncing seeded v5 Lima servers after DB refresh..."
    sync_v5_dev_lima_servers
  fi

  recreate_naked_lima_vm
  refresh_test_host_key

  echo "==> Restarting Horizon so workers use the latest code..."
  docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan horizon:terminate || true

  echo "==> Fresh dev environment is ready."
  limactl list | grep -E 'NAME|coold-dev|coolify-naked-test' || true
}

usage() {
  cat <<'USAGE'
Usage: scripts/dev.sh <command> [docker compose -f docker-compose.yml -f docker-compose.dev.yml args]

Commands:
  up      Start the coold VM, Docker Compose stack, and dev coold agent
  fresh   Recreate coold/naked Lima VMs, refresh DB, seed, sync v5 dev servers
  up --naked
          Start the coold VM(s) and Docker Compose stack only; skip host bootstrap so /v5 can bootstrap
  down    Stop the dev coold agent and Docker Compose stack
  down --cleanup
          Stop the dev stack, then delete the coold Lima VM(s) and VM-local state
  shell [hostname]
          Open a shell inside a coold VM by Lima hostname (default: coold-dev)
  list      Show Lima instances
  clean-vms Delete the coold Lima VMs and all VM-local runtime state (alias for down --cleanup)
  naked-vm Recreate the naked Lima VM used for bootstrap testing
  corrosion <command> Inspect Corrosion state, config, logs, and registered containers
  example-nginx <command> Start/check example nginx containers with coold DNS
  coolify <command> Install/run the released coolify dev helper
USAGE
}

cmd="${1:-}"
if [ $# -gt 0 ]; then
  shift
fi

case "$cmd" in
  up)
    up "$@"
    ;;
  down)
    down "$@"
    ;;
  fresh)
    fresh
    ;;
  shell)
    coold_vm_shell "${1:-}"
    ;;
  list)
    limactl list
    ;;
  clean-vms|clean-vm|reset-vms)
    down --cleanup
    ;;
  naked-vm)
    recreate_naked_lima_vm
    ;;
  corrosion)
    corrosion "$@"
    ;;
  example-nginx)
    example_nginx "$@"
    ;;
  coolify)
    coolify_dev "$@"
    ;;
  -h|--help|help|"")
    usage
    ;;
  *)
    echo "unknown command: $cmd" >&2
    usage >&2
    exit 1
    ;;
esac
