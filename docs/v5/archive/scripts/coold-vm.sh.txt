#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

read_coolify_env() {
  key="$1"
  default_value="$2"
  current_value="${!key:-}"

  if [ -n "$current_value" ]; then
    printf '%s\n' "$current_value"
    return
  fi

  if [ -f "$ROOT/.env" ]; then
    env_value="$(grep -E "^${key}=" "$ROOT/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | sed "s/^['\"]//; s/['\"]$//")"
    if [ -n "$env_value" ]; then
      printf '%s\n' "$env_value"
      return
    fi
  fi

  printf '%s\n' "$default_value"
}

INSTANCE="$(read_coolify_env COOLIFY_COOLD_LIMA_INSTANCE coold-dev)"
VERSION="$(read_coolify_env COOLIFY_COOLD_VERSION nightly)"
CORROSION_VERSION="$(read_coolify_env COOLIFY_CORROSION_VERSION v1.0.0)"
FLUX_URL="$(read_coolify_env COOLIFY_COOLD_VM_FLUX_URL http://host.lima.internal:6443)"
START_TIMEOUT="$(read_coolify_env COOLIFY_COOLD_VM_START_TIMEOUT 300)"
WG_IP="$(read_coolify_env COOLIFY_COOLD_VM_WG_IP "")"
WG_PEER_IP="$(read_coolify_env COOLIFY_COOLD_VM_WG_PEER_IP "")"
WG_PEER_ENDPOINT="$(read_coolify_env COOLIFY_COOLD_VM_WG_PEER_ENDPOINT "")"
WG_PEER_PUBLIC_KEY="$(read_coolify_env COOLIFY_COOLD_VM_WG_PEER_PUBLIC_KEY "")"
CONTAINER_SUBNET="$(read_coolify_env COOLIFY_COOLD_VM_CONTAINER_SUBNET 10.210.0.0/24)"
CONTAINER_GATEWAY="$(read_coolify_env COOLIFY_COOLD_VM_CONTAINER_GATEWAY 10.210.0.1)"
TEMPLATE="$ROOT/dev/lima/coold.yaml"
GUEST_COOLIFY_ROOT="/workspace/coolify"

usage() {
  cat <<USAGE
Usage: scripts/coold-vm.sh <command>

Commands:
  up       Create/start the Lima VM with minimal server prerequisites
  dev      Start packaged coold + Corrosion inside the VM
  start-agent
           Start production-like coold.service + corrosion.service inside the VM
  stop-agent
           Stop the VM coold.service + corrosion.service
  logs-agent
           Follow the VM coold.service + corrosion.service logs
  install-host-jwt [token]
           Install a Flux host JWT into the VM at /etc/coolify/host-jwt
  shell    Open a shell inside the VM
  status   Show Lima instance status
  stop     Stop the VM
  delete   Delete the VM and all VM-local runtime state

Environment:
  COOLIFY_COOLD_LIMA_INSTANCE  Override Lima instance name (default: coold-dev)
  COOLIFY_COOLD_VERSION        coold release tag to install (default: nightly)
  COOLIFY_CORROSION_VERSION    corrosion release tag to install (default: v1.0.0)
  COOLIFY_COOLD_VM_FLUX_URL    Flux gRPC URL visible from the VM (default: http://host.lima.internal:6443)
  COOLIFY_COOLD_VM_START_TIMEOUT Seconds to wait for Lima SSH/provisioning (default: 300)
  COOLIFY_COOLD_VM_WG_IP       Optional WireGuard mgmt IP for this host
  COOLIFY_COOLD_VM_CONTAINER_SUBNET Podman mesh subnet for this host
  COOLIFY_COOLD_VM_CONTAINER_GATEWAY Podman mesh gateway for this host

Guest mounts:
  $ROOT -> $GUEST_COOLIFY_ROOT

Installed from:
  https://github.com/coollabsio/coold/releases/tag/$VERSION
  https://github.com/superfly/corrosion/releases/tag/$CORROSION_VERSION
USAGE
}

require_lima() {
  command -v limactl >/dev/null 2>&1 || {
    echo "limactl is required. Install Lima first: brew install lima" >&2
    exit 1
  }
}

instance_exists() {
  limactl list 2>/dev/null | awk 'NR > 1 {print $1}' | grep -qx "$INSTANCE"
}

instance_running() {
  limactl list 2>/dev/null | awk -v name="$INSTANCE" 'NR > 1 && $1 == name {print $2}' | grep -qx Running
}

lima_shell() {
  (cd /tmp && limactl shell "$INSTANCE" -- "$@")
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

cleanup_lima_probe_processes() {
  kill_matching_processes "limactl shell ${INSTANCE} -- true"
  kill_matching_processes "ssh .*ControlPath=.*${INSTANCE}/ssh.sock"
  kill_matching_processes "ssh: .*/.lima/${INSTANCE}/ssh.sock"
  rm -f "$HOME/.lima/${INSTANCE}/ssh.sock"
}

cleanup_lima_hostagent_processes() {
  kill_matching_processes "limactl hostagent .*${INSTANCE}"
  rm -f "$HOME/.lima/${INSTANCE}/ha.sock"
}

lima_shell_timeout() {
  local timeout_seconds="$1"
  shift
  local pid
  local elapsed=0

  lima_shell "$@" &
  pid="$!"

  while kill -0 "$pid" >/dev/null 2>&1; do
    if [ "$elapsed" -ge "$timeout_seconds" ]; then
      pkill -P "$pid" >/dev/null 2>&1 || true
      kill "$pid" >/dev/null 2>&1 || true
      sleep 1
      pkill -P "$pid" >/dev/null 2>&1 || true
      kill -9 "$pid" >/dev/null 2>&1 || true
      wait "$pid" >/dev/null 2>&1 || true
      cleanup_lima_probe_processes
      return 124
    fi

    sleep 1
    elapsed=$((elapsed + 1))
  done

  wait "$pid"
}

vm_primary_ip() {
  lima_shell sh -lc "ip -4 route get 1.1.1.1 | awk '{print \$7; exit}'"
}

wireguard_public_key() {
  lima_shell sudo sh -lc 'install -d -m 0700 /etc/wireguard; if [ ! -s /etc/wireguard/privatekey ]; then wg genkey | tee /etc/wireguard/privatekey | wg pubkey > /etc/wireguard/publickey; chmod 600 /etc/wireguard/privatekey; fi; cat /etc/wireguard/publickey'
}

setup_wireguard() {
  local ip="${1:-$WG_IP}"
  local peer_ip="${2:-$WG_PEER_IP}"
  local peer_endpoint="${3:-$WG_PEER_ENDPOINT}"
  local peer_public_key="${4:-$WG_PEER_PUBLIC_KEY}"
  local listen_port="${5:-51820}"
  local peer_port="${6:-51820}"
  local peer_subnet="${7:-}"

  if [ -z "$ip" ]; then
    echo "ERROR: WireGuard IP is required." >&2
    exit 1
  fi

  wireguard_public_key >/dev/null

  if [ -n "$peer_ip" ] && [ -n "$peer_endpoint" ] && [ -n "$peer_public_key" ]; then
    lima_shell sudo sh -lc "cat >/etc/wireguard/wg0.conf.tmp <<WG
[Interface]
Address = ${ip}/32
ListenPort = ${listen_port}
PrivateKey = \$(cat /etc/wireguard/privatekey)

[Peer]
PublicKey = ${peer_public_key}
AllowedIPs = ${peer_ip}/32${peer_subnet:+, ${peer_subnet}}
Endpoint = ${peer_endpoint}:${peer_port}
PersistentKeepalive = 5
WG
chmod 600 /etc/wireguard/wg0.conf.tmp && mv /etc/wireguard/wg0.conf.tmp /etc/wireguard/wg0.conf && wg-quick down wg0 >/dev/null 2>&1 || true; wg-quick up wg0"
  else
    lima_shell sudo sh -lc "cat >/etc/wireguard/wg0.conf.tmp <<WG
[Interface]
Address = ${ip}/32
ListenPort = ${listen_port}
PrivateKey = \$(cat /etc/wireguard/privatekey)
WG
chmod 600 /etc/wireguard/wg0.conf.tmp && mv /etc/wireguard/wg0.conf.tmp /etc/wireguard/wg0.conf && wg-quick down wg0 >/dev/null 2>&1 || true; wg-quick up wg0"
  fi
}

install_host_jwt() {
  token="${1:-}"

  if [ -z "$token" ]; then
    token="$(cat)"
  fi

  if [ -z "$token" ]; then
    echo "ERROR: host JWT is empty." >&2
    exit 1
  fi

  printf '%s\n' "$token" | lima_shell sudo sh -c 'install -d -m 0755 /etc/coolify && cat > /tmp/coolify-host-jwt && install -m 0600 /tmp/coolify-host-jwt /etc/coolify/host-jwt && rm -f /tmp/coolify-host-jwt'
}


stop_agent_processes() {
  lima_shell sudo systemctl stop coold.service corrosion.service coold-dev-agent.service >/dev/null 2>&1 || true
  lima_shell sudo pkill -x coold >/dev/null 2>&1 || true
  lima_shell sudo pkill -x corrosion >/dev/null 2>&1 || true
}

ensure_podman_networks() {
  local current_subnet
  current_subnet="$(lima_shell sudo podman network inspect coolify-default-mesh --format '{{range .Subnets}}{{.Subnet}}{{end}}' 2>/dev/null || true)"

  if [ -n "$current_subnet" ] && [ "$current_subnet" != "$CONTAINER_SUBNET" ]; then
    lima_shell sudo podman network rm coolify-default-mesh >/dev/null
    current_subnet=""
  fi

  if [ -z "$current_subnet" ]; then
    lima_shell sudo podman network create --subnet "$CONTAINER_SUBNET" --gateway "$CONTAINER_GATEWAY" coolify-default-mesh >/dev/null
  fi
}



ensure_mesh_dns_anchor() {
  lima_shell sudo podman run -d --replace \
    --name coolify-v5-mesh-dns-anchor \
    --network coolify-default-mesh \
    docker.io/library/alpine:3.20 \
    sleep infinity >/dev/null
}

configure_system_resolved() {
  lima_shell sudo rm -f /etc/systemd/resolved.conf.d/coolify-internal.conf
  lima_shell sudo systemctl restart systemd-resolved.service
  lima_shell sudo resolvectl dns podman1 "$CONTAINER_GATEWAY"
  lima_shell sudo resolvectl domain podman1 '~coolify.internal'
  lima_shell sudo resolvectl default-route podman1 false
}
write_runtime_config() {
  local gossip_addr="127.0.0.1:8787"
  local bootstrap=""

  if [ -n "$WG_IP" ]; then
    gossip_addr="$WG_IP:8787"
  fi

  if [ -n "$WG_PEER_IP" ]; then
    bootstrap="\"$WG_PEER_IP:8787\""
  fi

  lima_shell sudo install -d -m 0755 /etc/corrosion/schemas /etc/coolify /run/coolify /var/lib/corrosion /var/run/corrosion /var/lib/coolify-dev

  lima_shell sudo tee /etc/corrosion/schemas/coolify.sql >/dev/null <<'SQL'
CREATE TABLE service_endpoints (
    container_id    TEXT NOT NULL DEFAULT '' PRIMARY KEY,
    container_name  TEXT NOT NULL DEFAULT '',
    namespace       TEXT NOT NULL DEFAULT '',
    host_mgmt_ip    TEXT NOT NULL DEFAULT '',
    container_ip    TEXT NOT NULL DEFAULT '',
    state           TEXT NOT NULL DEFAULT '',
    health          TEXT NOT NULL DEFAULT 'unknown',
    updated_at      INTEGER NOT NULL DEFAULT 0
);
SQL

  lima_shell sudo tee /etc/corrosion/config.toml >/dev/null <<TOML
[db]
path = "/var/lib/corrosion/corrosion.db"
schema_paths = ["/etc/corrosion/schemas"]

[gossip]
addr = "$gossip_addr"
bootstrap = [$bootstrap]
plaintext = true

[api]
addr = "127.0.0.1:8080"

[admin]
path = "/var/run/corrosion/admin.sock"
TOML

}

run_foreground() {
  stop_agent_processes
  write_runtime_config
  ensure_podman_networks
  configure_system_resolved
  ensure_mesh_dns_anchor
  install_mesh_firewall

  (cd /tmp && limactl shell "$INSTANCE" -- sudo \
    env COOLIFY_COOLD_HOST_MGMT_IP="${WG_IP:-127.0.0.1}" \
    COOLIFY_COOLD_FLUX_URL="$FLUX_URL" \
    CONTAINER_GATEWAY="$CONTAINER_GATEWAY" \
    bash -s) <<'RUNNER'
set -euo pipefail

echo "coold: $(/usr/local/bin/coold --version)"
echo "corrosion: $(/usr/local/bin/corrosion --version 2>/dev/null || cat /usr/local/bin/corrosion.version)"
echo "starting packaged coold endpoint with corrosion in local mode"

cleanup() {
  jobs -pr | xargs -r kill || true
}
trap cleanup EXIT INT TERM

/usr/local/bin/corrosion agent --config /etc/corrosion/config.toml &

COOLIFY_COOLD_HOST_MGMT_IP="${COOLIFY_COOLD_HOST_MGMT_IP:-127.0.0.1}" \
COOLIFY_COOLD_PODMAN_SOCKET="${COOLIFY_COOLD_PODMAN_SOCKET:-/run/podman/podman.sock}" \
COOLIFY_COOLD_CORROSION_URL="${COOLIFY_COOLD_CORROSION_URL:-http://127.0.0.1:8080}" \
COOLIFY_COOLD_NAMESPACES="${COOLIFY_COOLD_NAMESPACES:-default:coolify-default-mesh:$CONTAINER_GATEWAY}" \
COOLIFY_COOLD_DNS_ZONE="${COOLIFY_COOLD_DNS_ZONE:-coolify.internal}" \
COOLIFY_COOLD_FLUX_URL="${COOLIFY_COOLD_FLUX_URL:-http://host.lima.internal:6443}" \
COOLIFY_COOLD_HOST_JWT_PATH="${COOLIFY_COOLD_HOST_JWT_PATH:-/etc/coolify/host-jwt}" \
/usr/local/bin/coold &

wait
RUNNER
}

install_mesh_firewall() {
  lima_shell sudo install -d -m 0755 /etc/coolify
  lima_shell sudo touch /etc/coolify/allow.rules /etc/coolify/allow.nft

  lima_shell sudo tee /etc/coolify/bridge-fw.nft >/dev/null <<NFT
add table bridge coolify_bridge
add chain bridge coolify_bridge coolify_allow
flush chain bridge coolify_bridge coolify_allow
add chain bridge coolify_bridge coolify_intra
flush chain bridge coolify_bridge coolify_intra
add rule bridge coolify_bridge coolify_intra jump coolify_allow
add rule bridge coolify_bridge coolify_intra drop
add chain bridge coolify_bridge forward { type filter hook forward priority -200; policy accept; }
flush chain bridge coolify_bridge forward
add rule bridge coolify_bridge forward meta protocol != ip accept
add rule bridge coolify_bridge forward ct state established,related accept
add rule bridge coolify_bridge forward ip saddr { $CONTAINER_SUBNET } jump coolify_intra
add rule bridge coolify_bridge forward ip daddr { $CONTAINER_SUBNET } jump coolify_intra
NFT

  lima_shell sudo tee /etc/systemd/system/coolify-mesh-fw.service >/dev/null <<UNIT
[Unit]
Description=Coolify mesh firewall rules
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/sbin/sysctl -w net.ipv4.ip_forward=1
ExecStart=/bin/sh -c "/usr/sbin/iptables -t nat -C POSTROUTING -s $CONTAINER_SUBNET -o wg0 -j RETURN 2>/dev/null || /usr/sbin/iptables -t nat -I POSTROUTING -s $CONTAINER_SUBNET -o wg0 -j RETURN"
ExecStart=/bin/sh -c "/usr/sbin/iptables -D FORWARD -s $CONTAINER_SUBNET -j ACCEPT 2>/dev/null || true"
ExecStart=/bin/sh -c "/usr/sbin/iptables -D FORWARD -d $CONTAINER_SUBNET -j ACCEPT 2>/dev/null || true"
ExecStart=/bin/sh -c "/usr/sbin/iptables -N COOLIFY-ALLOW 2>/dev/null || true"
ExecStart=/bin/sh -c "/usr/sbin/iptables -N COOLIFY-INTRA 2>/dev/null || true"
ExecStart=/usr/sbin/iptables -F COOLIFY-ALLOW
ExecStart=/usr/sbin/iptables -F COOLIFY-INTRA
ExecStart=/usr/sbin/iptables -A COOLIFY-INTRA -j COOLIFY-ALLOW
ExecStart=/usr/sbin/iptables -A COOLIFY-INTRA -j DROP
ExecStart=/bin/sh -c "/usr/sbin/iptables -C FORWARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || /usr/sbin/iptables -I FORWARD 1 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT"
ExecStart=/bin/sh -c "/usr/sbin/iptables -C FORWARD -d $CONTAINER_SUBNET -j COOLIFY-INTRA 2>/dev/null || /usr/sbin/iptables -A FORWARD -d $CONTAINER_SUBNET -j COOLIFY-INTRA"
ExecStart=/bin/sh -c "/usr/sbin/iptables -C FORWARD -s $CONTAINER_SUBNET -j COOLIFY-INTRA 2>/dev/null || /usr/sbin/iptables -A FORWARD -s $CONTAINER_SUBNET -j COOLIFY-INTRA"
ExecStart=/bin/sh -c "nft delete table bridge coolify_bridge 2>/dev/null || true"
ExecStart=/bin/sh -c "nft -f /etc/coolify/bridge-fw.nft"
ExecStart=/bin/sh -c "[ -s /etc/coolify/allow.nft ] && nft -f /etc/coolify/allow.nft || true"

[Install]
WantedBy=multi-user.target
UNIT

  lima_shell sudo systemctl daemon-reload
  lima_shell sudo systemctl enable --now coolify-mesh-fw.service
  lima_shell sudo systemctl restart coolify-mesh-fw.service
}

start_agent() {
  stop_agent_processes
  write_runtime_config
  ensure_podman_networks
  configure_system_resolved
  ensure_mesh_dns_anchor
  install_mesh_firewall

  lima_shell sudo tee /etc/systemd/system/corrosion.service >/dev/null <<'UNIT'
[Unit]
Description=Corrosion local state store
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/corrosion agent --config /etc/corrosion/config.toml
Restart=on-failure
RestartSec=2s

[Install]
WantedBy=multi-user.target
UNIT

  lima_shell sudo tee /etc/systemd/system/coold.service >/dev/null <<UNIT
[Unit]
Description=Coolify host agent
Wants=corrosion.service
After=corrosion.service network-online.target podman.socket coolify-mesh-fw.service

[Service]
Environment=COOLIFY_COOLD_HOST_MGMT_IP=${WG_IP:-127.0.0.1}
Environment=COOLIFY_COOLD_PODMAN_SOCKET=/run/podman/podman.sock
Environment=COOLIFY_COOLD_CORROSION_URL=http://127.0.0.1:8080
Environment=COOLIFY_COOLD_NAMESPACES=default:coolify-default-mesh:$CONTAINER_GATEWAY
Environment=COOLIFY_COOLD_DNS_ZONE=coolify.internal
Environment=COOLIFY_COOLD_FLUX_URL=$FLUX_URL
Environment=COOLIFY_COOLD_HOST_JWT_PATH=/etc/coolify/host-jwt
ExecStart=/usr/local/bin/coold
AmbientCapabilities=CAP_NET_BIND_SERVICE CAP_NET_ADMIN CAP_NET_RAW
Restart=on-failure
RestartSec=2s

[Install]
WantedBy=multi-user.target
UNIT

  lima_shell sudo systemctl daemon-reload
  lima_shell sudo systemctl enable --now corrosion.service coold.service
  lima_shell sudo systemctl restart corrosion.service coold.service
}

start_vm() {
  if instance_running; then
    return
  fi

  cleanup_lima_probe_processes
  cleanup_lima_hostagent_processes

  if instance_exists; then
    limactl start --tty=false "$INSTANCE"
  else
    limactl start --tty=false --name="$INSTANCE" "$TEMPLATE"
  fi
}

latest_lima_message() {
  log_file="$HOME/.lima/$INSTANCE/ha.stderr.log"

  if [ ! -f "$log_file" ]; then
    echo "creating Lima instance directory"
    return 0
  fi

  grep -E '"msg":"(Starting VZ|Waiting for|The essential requirement|Executing /mnt/lima|SSH Local Port|Port is available|Attempting|Downloaded|Using the existing instance|The instance)' "$log_file"     | tail -n 1     | sed -E 's/^.*"msg":"(.*)","time":.*$/\1/'     | sed 's/\\"/"/g'     || true
}

wait_for_lima_start() {
  start_vm &
  start_pid=$!
  elapsed=0

  while kill -0 "$start_pid" 2>/dev/null; do
    if [ "$elapsed" -ge "$START_TIMEOUT" ]; then
      echo "ERROR: Lima start timed out after ${START_TIMEOUT}s for ${INSTANCE}." >&2
      kill "$start_pid" >/dev/null 2>&1 || true
      sleep 2
      kill -9 "$start_pid" >/dev/null 2>&1 || true
      wait "$start_pid" >/dev/null 2>&1 || true
      return 124
    fi

    if instance_exists && lima_shell_timeout 5 true >/dev/null 2>&1; then
      status="$(lima_shell cloud-init status 2>/dev/null || true)"
      printf '==> [%3ss] Lima start: guest SSH ready, cloud-init %s
' "$elapsed" "${status:-unknown}"
      lima_shell sudo sh -c 'test -f /var/log/cloud-init-output.log && tail -n 12 /var/log/cloud-init-output.log || true' 2>/dev/null         | awk '{ print "[guest] " $0; fflush(); }' || true

      if ! printf '%s' "$status" | grep -q running; then
        if kill -0 "$start_pid" 2>/dev/null; then
          kill "$start_pid" >/dev/null 2>&1 || true
          disown "$start_pid" >/dev/null 2>&1 || true
        fi
        return
      fi
    else
      message="$(latest_lima_message)"
      printf '==> [%3ss] Lima start: %s
' "$elapsed" "${message:-booting}"
    fi

    sleep 5
    elapsed=$((elapsed + 5))
  done

  wait "$start_pid" 2>/dev/null || true
}

wait_for_guest_provisioning() {
  echo "==> Waiting for guest SSH..."
  elapsed=0
  until instance_exists && lima_shell_timeout 5 true >/dev/null 2>&1; do
    if [ "$elapsed" -ge "$START_TIMEOUT" ]; then
      echo "ERROR: Guest SSH timed out after ${START_TIMEOUT}s for ${INSTANCE}." >&2
      return 124
    fi

    message="$(latest_lima_message)"
    printf '==> Waiting for guest SSH: %s
' "${message:-booting}"
    sleep 5
    elapsed=$((elapsed + 5))
  done

  status="$(lima_shell cloud-init status 2>/dev/null || true)"

  if printf '%s' "$status" | grep -q running; then
    echo "==> Guest SSH is ready; streaming cloud-init output until provisioning completes..."
    (
      lima_shell sudo sh -c 'touch /var/log/cloud-init-output.log; tail -n 40 -F /var/log/cloud-init-output.log' 2>/dev/null         | awk '{ print "[guest] " $0; fflush(); }'
    ) &
    tail_pid=$!

    while true; do
      if [ "$elapsed" -ge "$START_TIMEOUT" ]; then
        echo "ERROR: Guest provisioning timed out after ${START_TIMEOUT}s for ${INSTANCE}." >&2
        kill "$tail_pid" >/dev/null 2>&1 || true
        wait "$tail_pid" 2>/dev/null || true
        return 124
      fi

      status="$(lima_shell cloud-init status 2>/dev/null || true)"
      printf '==> Guest cloud-init: %s
' "${status:-unknown}"

      if ! printf '%s' "$status" | grep -q running; then
        break
      fi

      sleep 5
      elapsed=$((elapsed + 5))
    done

    kill "$tail_pid" >/dev/null 2>&1 || true
    wait "$tail_pid" 2>/dev/null || true
  else
    printf '==> Guest cloud-init: %s
' "${status:-unknown}"
  fi

  echo "==> Final guest provisioning status:"
  lima_shell bash -lc 'cloud-init status 2>/dev/null || true; echo "minimal VM ready"; true' \
    | awk '{ print "[guest] " $0; fflush(); }' || true
}

ensure_mdns_hostname() {
  local current_hostname
  current_hostname="$(lima_shell hostname 2>/dev/null || true)"

  if [ "$current_hostname" != "$INSTANCE" ]; then
    echo "==> Setting guest hostname to ${INSTANCE} for ${INSTANCE}.local mDNS..."
    lima_shell sudo hostnamectl set-hostname "$INSTANCE"
  fi

  lima_shell sudo systemctl restart avahi-daemon.service
}

up_with_logs() {
  echo "==> Coolify coold VM: $INSTANCE"
  echo "==> coold package tag: $VERSION"
  echo "==> corrosion package tag: $CORROSION_VERSION"
  echo "==> Lima config: $TEMPLATE"

  wait_for_lima_start
  wait_for_guest_provisioning
  ensure_mdns_hostname

  echo "==> VM is ready. Run coolify bootstrap via: scripts/dev.sh up"
}

cmd="${1:-}"
case "$cmd" in
  up)
    require_lima
    up_with_logs
    ;;
  dev)
    require_lima
    start_vm >/dev/null
    run_foreground
    ;;
  start-agent)
    require_lima
    start_vm >/dev/null
    start_agent
    ;;
  stop-agent)
    require_lima
    if instance_running; then
      stop_agent_processes
    fi
    ;;
  logs-agent)
    require_lima
    start_vm >/dev/null
    exec bash -lc "cd /tmp && limactl shell '$INSTANCE' -- sudo journalctl -u coold.service -u corrosion.service -f -n 100"
    ;;
  install-host-jwt)
    require_lima
    start_vm >/dev/null
    install_host_jwt "${2:-}"
    ;;
  wg-public-key)
    require_lima
    start_vm >/dev/null
    wireguard_public_key
    ;;
  vm-ip)
    require_lima
    start_vm >/dev/null
    vm_primary_ip
    ;;
  setup-wireguard)
    require_lima
    start_vm >/dev/null
    setup_wireguard "${2:-}" "${3:-}" "${4:-}" "${5:-}" "${6:-}" "${7:-}" "${8:-}"
    ;;
  shell)
    require_lima
    start_vm >/dev/null
    exec bash -lc "cd /tmp && limactl shell '$INSTANCE' -- sudo env TERM=xterm-256color SYSTEMD_PAGER=cat SYSTEMD_LESS=FRXMK bash -l"
    ;;
  status)
    require_lima
    if instance_exists; then
      exec limactl list "$INSTANCE"
    fi

    echo "No Lima instance named '$INSTANCE' exists yet."
    echo "Run: scripts/coold-vm.sh up"
    ;;
  stop)
    require_lima
    exec limactl stop "$INSTANCE"
    ;;
  delete|destroy)
    require_lima
    exec limactl delete --force --tty=false "$INSTANCE"
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
