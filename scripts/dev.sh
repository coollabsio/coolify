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

coold_vm_container_subnet() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_CONTAINER_SUBNET_${index}" "10.210.$((index - 1)).0/24"
}

coold_vm_container_gateway() {
  local index="$1"
  read_coolify_env "COOLIFY_COOLD_VM_CONTAINER_GATEWAY_${index}" "10.210.$((index - 1)).1"
}

host_arch() {
  case "$(uname -m)" in
    x86_64|amd64)
      printf '%s\n' amd64
      ;;
    arm64|aarch64)
      printf '%s\n' arm64
      ;;
    *)
      echo "ERROR: unsupported host architecture: $(uname -m)" >&2
      exit 1
      ;;
  esac
}

host_os() {
  case "$(uname -s)" in
    Darwin)
      printf '%s\n' darwin
      ;;
    Linux)
      printf '%s\n' linux
      ;;
    *)
      echo "ERROR: unsupported host OS: $(uname -s)" >&2
      exit 1
      ;;
  esac
}

coolify_cli_bin() {
  printf '%s\n' "$ROOT/.dev/bin/coolify"
}

ensure_coolify() {
  local bin
  local version
  local arch
  local os
  local url
  local tmpdir

  bin="$(coolify_cli_bin)"
  version="$(read_coolify_env COOLIFY_CLI_VERSION "$(read_coolify_env COOLIFY_COOLD_VERSION nightly)")"
  arch="$(host_arch)"
  os="$(host_os)"

  if [ -x "$bin" ] && "$bin" --version >/dev/null 2>&1 && [ "${COOLIFY_CLI_FORCE_DOWNLOAD:-false}" != "true" ]; then
    return
  fi

  url="https://github.com/coollabsio/coold/releases/download/${version}/coolify-${os}-${arch}.tar.gz"
  echo "==> Installing coolify from ${url}"

  mkdir -p "$(dirname "$bin")"
  tmpdir="$(mktemp -d)"
  if ! curl -fsSL --retry 3 --max-time 120 -o "$tmpdir/coolify.tar.gz" "$url"; then
    rm -rf "$tmpdir"
    return 1
  fi
  tar -xzf "$tmpdir/coolify.tar.gz" -C "$tmpdir"
  install -m 0755 "$tmpdir/coolify" "$bin"
  rm -rf "$tmpdir"
}

lima_ssh_target() {
  local index="$1"
  local instance

  instance="$(coold_vm_instance "$index")"

  if [ ! -f "$HOME/.lima/${instance}/ssh.config" ]; then
    echo "ERROR: Lima SSH config for ${instance} was not found. Start it first with scripts/dev.sh up." >&2
    exit 1
  fi

  printf 'lima-%s\n' "$instance"
}

lima_ssh_config() {
  local count
  local config="$ROOT/.dev/lima/ssh.config"
  local instance
  count="$(coold_vm_count)"

  mkdir -p "$(dirname "$config")"
  : > "$config"

  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    if [ ! -f "$HOME/.lima/${instance}/ssh.config" ]; then
      echo "ERROR: Lima SSH config for ${instance} was not found. Start it first with scripts/dev.sh up." >&2
      exit 1
    fi

    cat "$HOME/.lima/${instance}/ssh.config" >> "$config"
    printf '\n' >> "$config"
  done

  printf '%s\n' "$config"
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
    overrides="${overrides}${node}=host.lima.internal:$(coold_vm_wg_port "$index")"
  done

  printf '%s\n' "$overrides"
}

coolify_ssh_key() {
  read_coolify_env COOLIFY_CLI_SSH_KEY "$HOME/.lima/_config/user"
}

coolify_ssh_user() {
  read_coolify_env COOLIFY_CLI_SSH_USER "$USER"
}

coolify_bootstrap_command() {
  local nodes
  local ssh_config
  local listen_overrides
  local endpoint_overrides
  ensure_coolify

  nodes="$(coolify_nodes_arg)" || return 1
  ssh_config="$(lima_ssh_config)" || return 1
  listen_overrides="$(coolify_wg_listen_overrides_arg)" || return 1
  endpoint_overrides="$(coolify_wg_endpoint_overrides_arg)" || return 1

  cat <<CMD
$(coolify_cli_bin) init bootstrap \\
  --nodes "${nodes}" \\
  --ssh-config "${ssh_config}" \\
  --ssh-user "$(coolify_ssh_user)" \\
  --wg-listen-port-overrides "${listen_overrides}" \\
  --wg-endpoint-overrides "${endpoint_overrides}" \\
  --coold-version "$(read_coolify_env COOLIFY_COOLD_VERSION nightly)" \\
  --corrosion-version "$(read_coolify_env COOLIFY_CORROSION_VERSION v1.0.0)" \\
  --yes
CMD
}

coolify_bootstrap() {
  local nodes
  local ssh_config
  local listen_overrides
  local endpoint_overrides
  ensure_coolify

  nodes="$(coolify_nodes_arg)" || return 1
  ssh_config="$(lima_ssh_config)" || return 1
  listen_overrides="$(coolify_wg_listen_overrides_arg)" || return 1
  endpoint_overrides="$(coolify_wg_endpoint_overrides_arg)" || return 1

  "$(coolify_cli_bin)" init bootstrap \
    --nodes "$nodes" \
    --ssh-config "$ssh_config" \
    --ssh-user "$(coolify_ssh_user)" \
    --wg-listen-port-overrides "$listen_overrides" \
    --wg-endpoint-overrides "$endpoint_overrides" \
    --coold-version "$(read_coolify_env COOLIFY_COOLD_VERSION nightly)" \
    --corrosion-version "$(read_coolify_env COOLIFY_CORROSION_VERSION v1.0.0)" \
    --yes
}

coolify_bootstrap_with_retry() {
  local attempt
  local attempts=5

  for attempt in $(seq 1 "$attempts"); do
    if coolify_bootstrap; then
      return
    fi

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
      ensure_coolify
      "$(coolify_cli_bin)" --version
      ;;
    path)
      ensure_coolify
      coolify_cli_bin
      ;;
    bootstrap-command)
      coolify_bootstrap_command
      ;;
    run)
      ensure_coolify
      exec "$(coolify_cli_bin)" "$@"
      ;;
    -h|--help|help)
      cat <<'USAGE'
Usage: scripts/dev.sh coolify <command>

Commands:
  install             Download/install the nightly coolify dev binary
  path                Print the local coolify path
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

mint_host_jwt_for_host() {
  local host_id="$1"
  local attempts=60
  local output
  local caps
  local builder_capacity

  builder_capacity="$(read_coolify_env COOLIFY_COOLD_VM_BUILDER_CAPACITY 2)"
  caps="coold"
  if [ "$builder_capacity" != "0" ]; then
    caps="coold,builder"
  fi

  for attempt in $(seq 1 "$attempts"); do
    if output="$(spin exec -T coolify php artisan flux:dev "$host_id" --caps="$caps" 2>&1)"; then
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

  spin logs -f
}

sync_v5_dev_lima_servers() {
  local ssh_user

  ssh_user="$(coolify_ssh_user)"

  echo "==> Running pending migrations before syncing v5 dev Lima state..."
  spin exec -T coolify php artisan migrate --force

  echo "==> Seeding dev Lima VM(s) into v5 clusters/servers..."
  spin exec -T \
    -e COOLIFY_CLI_SSH_USER="$ssh_user" \
    coolify php artisan db:seed --class=V5DevLimaSeeder --force
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
  local spin_args=()
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
        spin_args+=("$1")
        shift
        ;;
    esac
  done

  if [ "$coold_vm_enabled" != "false" ]; then
    echo "==> Starting ${count} Coolify coold VM(s) before Spin..."
    for index in $(seq 1 "$count"); do
      coold_vm "$index" up
    done
  else
    echo "==> COOLIFY_COOLD_VM_ENABLED=false; skipping coold VM."
  fi

  echo "==> Starting Coolify Docker stack with Spin..."
  if [ "${#spin_args[@]}" -gt 0 ]; then
    spin up -d "${spin_args[@]}"
  else
    spin up -d
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
    echo "==> Dev environment is ready. Use 'spin logs -f' or 'scripts/coold-vm.sh logs-agent' to follow logs."
    return
  fi

  follow_logs "$coold_vm_enabled"
}

down() {
  local coold_vm_enabled
  local stop_coold_vm
  local cleanup=false
  local spin_args=()
  coold_vm_enabled="$(read_coolify_env COOLIFY_COOLD_VM_ENABLED true)"
  stop_coold_vm="$(read_coolify_env COOLIFY_COOLD_VM_STOP_ON_DOWN false)"

  while [ $# -gt 0 ]; do
    case "$1" in
      --cleanup)
        cleanup=true
        shift
        ;;
      *)
        spin_args+=("$1")
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

  echo "==> Stopping Coolify Docker stack with Spin..."
  if [ "${#spin_args[@]}" -gt 0 ]; then
    spin down "${spin_args[@]}"
  else
    spin down
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

clean_vms() {
  local count
  count="$(coold_vm_count)"

  echo "==> Deleting ${count} coold Lima VM(s). This removes VM-local state."
  for index in $(seq 1 "$count"); do
    instance="$(coold_vm_instance "$index")"
    echo "==> Deleting ${instance}..."
    limactl stop --force --tty=false "$instance" >/dev/null 2>&1 || true
    if ! run_with_timeout 60 limactl delete --force --tty=false "$instance"; then
      echo "WARN: limactl delete timed out for ${instance}; killing matching limactl clients." >&2
      pkill -f "limactl.*${instance}" >/dev/null 2>&1 || true
      rm -rf "$HOME/.lima/${instance}"
    fi
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
    echo "ERROR: example-nginx ping/firewall commands require COOLIFY_COOLD_VM_COUNT=2." >&2
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

example_nginx_firewall_up() {
  local src
  local dst
  example_nginx_require_pair

  src="$(example_nginx_container_ip 1)"
  dst="$(example_nginx_container_ip 2)"

  scripts/dev.sh firewall allow "$src" "$dst" tcp 80
}

example_nginx_firewall_down() {
  local src
  local dst
  example_nginx_require_pair

  src="$(example_nginx_container_ip 1)"
  dst="$(example_nginx_container_ip 2)"

  scripts/dev.sh firewall revoke "$src" "$dst" tcp 80
}

example_nginx_help() {
  cat <<'USAGE'
Usage: scripts/dev.sh example-nginx <command>

Commands:
  up             Start one nginx container on each coold VM with coold DNS configured
  down           Remove the example nginx containers
  check-dns      Verify host 1 nginx can resolve host 2 nginx through coold DNS
  ping           Verify host 1 nginx can reach host 2 nginx on tcp/80
  firewall up    Allow host 1 nginx to reach host 2 nginx through the coolify CLI
  firewall down  Revoke the example nginx tcp/80 allow rule through the coolify CLI
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
    firewall)
      case "${1:-help}" in
        up)
          example_nginx_firewall_up
          ;;
        down)
          example_nginx_firewall_down
          ;;
        *)
          echo "unknown example-nginx firewall command: ${1:-help}" >&2
          echo "Run: scripts/dev.sh example-nginx help" >&2
          exit 1
          ;;
      esac
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

firewall_help() {
  cat <<'USAGE'
Usage: scripts/dev.sh firewall <command>

Commands:
  allow <src> <dst> [proto] [port]  Allow traffic through the coolify CLI (proto/port optional)
  revoke [id|src] [dst] [proto] [port]
                                    Remove an allow rule through the coolify CLI
  list                              List allow rules through the coolify CLI
  containers                        List registered containers through the coolify CLI

Examples:
  scripts/dev.sh firewall allow 10.210.0.2 10.210.1.2 tcp 80
  scripts/dev.sh firewall revoke
  scripts/dev.sh firewall revoke 10.210.0.2 10.210.1.2 tcp 80
  scripts/dev.sh firewall revoke 3ba6e0c235a6
  scripts/dev.sh firewall list
USAGE
}

coolify_firewall() {
  local command="$1"
  shift
  local nodes
  local ssh_config

  ensure_coolify
  nodes="$(coolify_nodes_arg)" || return 1
  ssh_config="$(lima_ssh_config)" || return 1

  "$(coolify_cli_bin)" firewall "$command" \
    --nodes "$nodes" \
    --ssh-config "$ssh_config" \
    --ssh-user "$(coolify_ssh_user)" \
    "$@"
}

firewall_allow() {
  local src="${1:-}"
  local dst="${2:-}"
  local proto="${3:-}"
  local port="${4:-}"
  local args=()

  if [ -z "$src" ] || [ -z "$dst" ]; then
    firewall_help >&2
    exit 1
  fi

  if [ -n "$port" ] && [ -z "$proto" ]; then
    echo "ERROR: port requires proto (tcp or udp)." >&2
    exit 1
  fi

  args+=(--from "$src" --to "$dst")
  if [ -n "$proto" ]; then
    args+=(--proto "$proto")
  fi
  if [ -n "$port" ]; then
    args+=(--port "$port")
  fi

  coolify_firewall allow "${args[@]}"
}

firewall_revoke() {
  local id_or_src="${1:-}"
  local dst="${2:-}"
  local proto="${3:-}"
  local port="${4:-}"
  local args=()

  if [ -z "$id_or_src" ]; then
    echo "Current firewall allow rule IDs:"
    firewall_list
    echo
    echo "Revoke one with: scripts/dev.sh firewall revoke <id>"
    return
  fi

  if [ -z "$dst" ]; then
    args+=(--id "$id_or_src")
  else
    args+=(--from "$id_or_src" --to "$dst")
    if [ -n "$proto" ]; then
      args+=(--proto "$proto")
    fi
    if [ -n "$port" ]; then
      args+=(--port "$port")
    fi
  fi

  coolify_firewall revoke "${args[@]}"
}

firewall_list() {
  coolify_firewall list "$@"
}

firewall_containers() {
  coolify_firewall containers "$@"
}

firewall() {
  local command="${1:-help}"
  if [ $# -gt 0 ]; then
    shift
  fi

  case "$command" in
    allow)
      firewall_allow "$@"
      ;;
    revoke|remove|delete|deny)
      firewall_revoke "$@"
      ;;
    list)
      firewall_list "$@"
      ;;
    containers)
      firewall_containers "$@"
      ;;
    -h|--help|help)
      firewall_help
      ;;
    *)
      echo "unknown firewall command: $command" >&2
      echo "Run: scripts/dev.sh firewall help" >&2
      exit 1
      ;;
  esac
}

usage() {
  cat <<'USAGE'
Usage: scripts/dev.sh <command> [spin args]

Commands:
  up      Start the coold VM, Spin stack, and dev coold agent
  up --naked
          Start the coold VM(s) and Spin stack only; skip host bootstrap so /v5 can bootstrap
  down    Stop the dev coold agent and Spin stack
  down --cleanup
          Stop the dev stack, then delete the coold Lima VM(s) and VM-local state
  shell [n] Open a shell inside coold VM n (default: 1)
  list      Show Lima instances
  clean-vms Delete the coold Lima VMs and all VM-local runtime state (alias for down --cleanup)
  corrosion <command> Inspect Corrosion state, config, logs, and registered containers
  firewall <command>  Manage dev coold firewall allow rules
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
  shell)
    coold_vm "${1:-1}" shell
    ;;
  list)
    limactl list
    ;;
  clean-vms|clean-vm|reset-vms)
    down --cleanup
    ;;
  corrosion)
    corrosion "$@"
    ;;
  firewall)
    firewall "$@"
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
