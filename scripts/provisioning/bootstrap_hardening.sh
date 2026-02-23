#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_VERSION="1.2.1"
SCRIPT_NAME="$(basename "$0")"

LOG_FILE="/var/log/bootstrap-hardening.log"
REPORT_FILE="/var/log/bootstrap-hardening-report.json"
STATE_DIR="/var/lib/bootstrap-hardening"
STATE_FILE="${STATE_DIR}/state"

SSH_DROPIN_FILE="/etc/ssh/sshd_config.d/00-coolify-hardening.conf"
JOURNALD_DROPIN_FILE="/etc/systemd/journald.conf.d/60-persistent.conf"
AUDIT_RULES_FILE="/etc/audit/rules.d/60-coolify-baseline.rules"
DOCKER_USER_SCRIPT="/usr/local/sbin/docker-user-hardening.sh"
DOCKER_USER_ENV_FILE="/etc/default/docker-user-hardening"
DOCKER_USER_UNIT_FILE="/etc/systemd/system/docker-user-hardening.service"
APT_AUTO_FILE="/etc/apt/apt.conf.d/20auto-upgrades"
APT_LOCAL_FILE="/etc/apt/apt.conf.d/52unattended-upgrades-local"
SYSCTL_DROPIN_FILE="/etc/sysctl.d/60-coolify-hardening.conf"
FAIL2BAN_JAIL_FILE="/etc/fail2ban/jail.d/coolify-hardening.local"
COOLIFY_BINDING_GUARD_SCRIPT="/usr/local/sbin/coolify-binding-guard.sh"
COOLIFY_BINDING_GUARD_SERVICE="/etc/systemd/system/coolify-binding-guard.service"
COOLIFY_BINDING_GUARD_TIMER="/etc/systemd/system/coolify-binding-guard.timer"

TAILSCALE_IFACE="tailscale0"
COOLIFY_ENV_FILE="/data/coolify/source/.env"

ADMIN_USER="${ADMIN_USER:-}"
ADMIN_PUBKEY="${ADMIN_PUBKEY:-}"
TAILSCALE_CIDR="${TAILSCALE_CIDR:-100.64.0.0/10}"
SSH_PORT="${SSH_PORT:-22}"
WAN_IFACE="${WAN_IFACE:-}"
ENABLE_AUTO_REBOOT="${ENABLE_AUTO_REBOOT:-true}"
AUTO_REBOOT_TIME="${AUTO_REBOOT_TIME:-03:30}"
JOURNAL_RETENTION="${JOURNAL_RETENTION:-3month}"
JOURNAL_MAX_USE="${JOURNAL_MAX_USE:-1G}"
TUNNEL_MODE="${TUNNEL_MODE:-false}"
SWAP_SIZE="${SWAP_SIZE:-2G}"
DRY_RUN="${DRY_RUN:-false}"
FORCE="${FORCE:-false}"
UPGRADE_MAIL="${UPGRADE_MAIL:-}"
BIND_DASHBOARD_TO_TAILSCALE="${BIND_DASHBOARD_TO_TAILSCALE:-false}"
INSTALL_TAILSCALE="${INSTALL_TAILSCALE:-false}"
TAILSCALE_AUTH_KEY="${TAILSCALE_AUTH_KEY:-}"

OS_VERSION=""
DOCKER_PRESENT="false"
DOCKER_RULES_APPLIED="false"
DOCKER_DAEMON_NEEDS_RESTART="false"

log() {
  printf '[%s] %s\n' "$(date -Iseconds)" "$*"
}

warn() {
  log "WARN: $*"
}

die() {
  log "ERROR: $*"
  exit 1
}

on_err() {
  local line_no="$1"
  local cmd="$2"
  log "ERROR: command failed at line ${line_no}: ${cmd}"
}

trap 'on_err "${LINENO}" "${BASH_COMMAND}"' ERR

is_true() {
  case "${1,,}" in
    1|true|yes|y|on) return 0 ;;
    *) return 1 ;;
  esac
}

require_value() {
  local opt="$1"
  local val="${2:-}"
  [[ -n "${val}" ]] || die "Option ${opt} requires a value."
}

usage() {
  cat <<'EOF'
Ubuntu Coolify bootstrap hardening script.

Usage:
  bootstrap_hardening.sh --admin-user <name> --admin-pubkey "<ssh key>" [options]

Required:
  --admin-user <name>           Admin user to create/ensure and allow via SSH
  --admin-pubkey "<ssh key>"    SSH public key to add for admin user

Optional:
  --tailscale-cidr <cidr>       Tailscale CIDR hint (default: 100.64.0.0/10)
  --ssh-port <port>             SSH port (default: 22)
  --wan-iface <iface>           WAN interface (default: auto-detected)
  --tunnel-mode                 Skip WAN 80/443 rules (Cloudflare Tunnel / outbound-only)
  --swap-size <size>            Swap file size (default: 2G; format: <N>G or <N>M; 0 to skip)
  --enable-auto-reboot <bool>   Enable unattended-upgrades reboot (default: true)
  --auto-reboot-time <HH:MM>    Reboot time for unattended-upgrades (default: 03:30)
  --journal-retention <span>   Journal retention period (default: 3month)
  --bind-dashboard-to-tailscale Bind Coolify dashboard to Tailscale IP only (split-horizon)
  --install-tailscale           Install Tailscale if not present (requires --tailscale-auth-key or interactive)
  --tailscale-auth-key <key>    Tailscale auth key for non-interactive setup (use with --install-tailscale)
  --upgrade-mail <address>      Email for unattended-upgrade failure reports (optional)
  --env-file <path>             Source variables from file before parsing flags
  --dry-run                     Print actions without changing system
  --force                       Override non-Tailscale SSH-session safety gate
  -h, --help                    Show this help

Environment variables are also supported for all options above.
Env-file uses the same variable names (ADMIN_USER, SSH_PORT, TUNNEL_MODE, etc.).
CLI flags override env-file values.

Dashboard Tailscale Restriction (--bind-dashboard-to-tailscale):
  Verifies and re-applies UFW rules restricting Coolify dashboard (port 8000), Soketi
  (port 6001), and terminal (port 6002) to the tailscale0 interface only, then installs
  a watchdog timer that periodically re-applies them if removed. UFW rules on tailscale0
  are always configured by the hardening step; this flag adds a periodic verification
  layer on top.
EOF
}

run() {
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: $*"
    return 0
  fi
  "$@"
}

write_file() {
  local path="$1"
  local mode="$2"
  local owner="$3"
  local group="$4"
  local tmp

  tmp="$(mktemp)"
  cat > "${tmp}"

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: write ${path}"
    rm -f "${tmp}"
    return 0
  fi

  install -d -m 0755 "$(dirname "${path}")"
  install -m "${mode}" -o "${owner}" -g "${group}" "${tmp}" "${path}"
  rm -f "${tmp}"
}

parse_args() {
  # Pre-scan for --env-file to source it before parsing other args
  local env_file=""
  local arg
  for arg in "$@"; do
    if [[ "${arg}" == --env-file=* ]]; then
      env_file="${arg#--env-file=}"
    fi
  done
  if [[ -z "${env_file}" ]]; then
    local prev=""
    for arg in "$@"; do
      if [[ "${prev}" == "--env-file" ]]; then
        env_file="${arg}"
        break
      fi
      prev="${arg}"
    done
  fi
  if [[ -n "${env_file}" ]]; then
    [[ -f "${env_file}" ]] || die "Env file not found: ${env_file}"
    local file_perms
    file_perms="$(stat -c '%a' "${env_file}" 2>/dev/null || stat -f '%Lp' "${env_file}" 2>/dev/null || echo "unknown")"
    if [[ "${file_perms}" != "unknown" && "${file_perms}" != "600" && "${file_perms}" != "400" ]]; then
      warn "Env file ${env_file} has permissions ${file_perms}; recommend 0600 or stricter."
    fi
    # shellcheck disable=SC1090
    source "${env_file}"
  fi

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --env-file)
        # Already processed in pre-scan; consume and skip
        require_value "$1" "${2:-}"
        shift 2
        ;;
      --env-file=*)
        # Already processed in pre-scan; skip
        shift
        ;;
      --admin-user)
        require_value "$1" "${2:-}"
        ADMIN_USER="$2"
        shift 2
        ;;
      --admin-pubkey)
        require_value "$1" "${2:-}"
        ADMIN_PUBKEY="$2"
        shift 2
        ;;
      --tailscale-cidr)
        require_value "$1" "${2:-}"
        TAILSCALE_CIDR="$2"
        shift 2
        ;;
      --ssh-port)
        require_value "$1" "${2:-}"
        SSH_PORT="$2"
        shift 2
        ;;
      --wan-iface)
        require_value "$1" "${2:-}"
        WAN_IFACE="$2"
        shift 2
        ;;
      --enable-auto-reboot)
        require_value "$1" "${2:-}"
        ENABLE_AUTO_REBOOT="$2"
        shift 2
        ;;
      --auto-reboot-time)
        require_value "$1" "${2:-}"
        AUTO_REBOOT_TIME="$2"
        shift 2
        ;;
      --journal-retention)
        require_value "$1" "${2:-}"
        JOURNAL_RETENTION="$2"
        shift 2
        ;;
      --swap-size)
        require_value "$1" "${2:-}"
        SWAP_SIZE="$2"
        shift 2
        ;;
      --tunnel-mode)
        TUNNEL_MODE="true"
        shift
        ;;
      --bind-dashboard-to-tailscale)
        BIND_DASHBOARD_TO_TAILSCALE="true"
        shift
        ;;
      --install-tailscale)
        INSTALL_TAILSCALE="true"
        shift
        ;;
      --tailscale-auth-key)
        require_value "$1" "${2:-}"
        TAILSCALE_AUTH_KEY="$2"
        shift 2
        ;;
      --dry-run)
        DRY_RUN="true"
        shift
        ;;
      --force)
        FORCE="true"
        shift
        ;;
      --upgrade-mail)
        require_value "$1" "${2:-}"
        UPGRADE_MAIL="$2"
        shift 2
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        die "Unknown option: $1 (use --help)"
        ;;
    esac
  done
}

setup_logging() {
  if is_true "${DRY_RUN}"; then
    log "Dry-run enabled; no host changes will be applied."
    return 0
  fi

  install -d -m 0750 /var/log
  touch "${LOG_FILE}"
  chmod 0600 "${LOG_FILE}"
  exec > >(tee -a "${LOG_FILE}") 2>&1
}

require_root() {
  [[ "$(id -u)" -eq 0 ]] || die "Run as root."
}

validate_pubkey() {
  printf '%s\n' "${ADMIN_PUBKEY}" | awk '
    $1 ~ /^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh.com|sk-ecdsa-sha2-nistp256@openssh.com)$/ && NF >= 2 { ok=1 }
    END { exit(ok ? 0 : 1) }
  ' || die "ADMIN_PUBKEY does not look like a valid SSH public key."
}

validate_inputs() {
  [[ -n "${ADMIN_USER}" ]] || die "Missing ADMIN_USER / --admin-user."
  [[ -n "${ADMIN_PUBKEY}" ]] || die "Missing ADMIN_PUBKEY / --admin-pubkey."
  [[ "${ADMIN_USER}" != "root" ]] || die "ADMIN_USER must not be root."
  [[ "${ADMIN_USER}" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || die "ADMIN_USER is not a valid Linux username."
  [[ "${SSH_PORT}" =~ ^[0-9]+$ ]] || die "SSH_PORT must be numeric."
  (( SSH_PORT >= 1 && SSH_PORT <= 65535 )) || die "SSH_PORT must be in range 1..65535."
  [[ "${AUTO_REBOOT_TIME}" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "AUTO_REBOOT_TIME must be HH:MM (24h)."

  # Validate ENABLE_AUTO_REBOOT is either true or false (or recognized variant)
  local reboot_lower="${ENABLE_AUTO_REBOOT,,}"
  if [[ "${reboot_lower}" != "true" && "${reboot_lower}" != "false" && "${reboot_lower}" != "1" && "${reboot_lower}" != "0" && "${reboot_lower}" != "yes" && "${reboot_lower}" != "no" ]]; then
    die "ENABLE_AUTO_REBOOT must be true/false (got: ${ENABLE_AUTO_REBOOT})."
  fi

  [[ "${JOURNAL_RETENTION}" =~ ^[0-9]+(us(ec)?|ms(ec)?|s(ec(ond)?s?)?|m(in(ute)?s?)?|h(our)?s?|d(ay)?s?|w(eek)?s?|month?s?|y(ear)?s?)$ ]] \
    || die "JOURNAL_RETENTION must be a valid systemd time span (e.g. 3month, 4w, 90d)."

  if [[ "${SWAP_SIZE}" != "0" ]]; then
    [[ "${SWAP_SIZE}" =~ ^[0-9]+[GgMm]$ ]] || die "SWAP_SIZE must be <N>G or <N>M (e.g. 2G, 512M), or 0 to skip."
  fi

  # Validate split-horizon binding options
  if is_true "${BIND_DASHBOARD_TO_TAILSCALE}" && ! is_true "${DRY_RUN}"; then
    [[ -f "${COOLIFY_ENV_FILE}" ]] \
      || die "Coolify .env not found at ${COOLIFY_ENV_FILE}. Is Coolify installed?"
    command -v docker >/dev/null 2>&1 \
      || die "--bind-dashboard-to-tailscale requires Docker."
    docker compose version >/dev/null 2>&1 \
      || die "--bind-dashboard-to-tailscale requires the Docker Compose plugin."
    [[ -d "/data/coolify/source" ]] \
      || die "--bind-dashboard-to-tailscale requires /data/coolify/source to exist."
  fi

  # Validate Tailscale install options
  if is_true "${INSTALL_TAILSCALE}"; then
    # If Tailscale is not already installed and no auth key provided, warn about interactive mode
    if ! command -v tailscale >/dev/null 2>&1 && [[ -z "${TAILSCALE_AUTH_KEY}" ]]; then
      warn "INSTALL_TAILSCALE is set but TAILSCALE_AUTH_KEY not provided. Interactive auth required."
    fi
  fi

  validate_pubkey
}

detect_os() {
  [[ -f /etc/os-release ]] || die "/etc/os-release not found."
  # shellcheck disable=SC1091
  source /etc/os-release
  [[ "${ID:-}" == "ubuntu" ]] || die "Only Ubuntu is supported."
  OS_VERSION="${VERSION_ID:-unknown}"

  if [[ "${OS_VERSION}" != "24.04" ]] && ! is_true "${FORCE}"; then
    die "Expected Ubuntu 24.04.x (found ${OS_VERSION}). Use --force to override."
  fi
}

check_disk_space() {
  local swap_size="${SWAP_SIZE:-2G}"
  local required_mb=512
  if [[ "${swap_size}" != "0" ]]; then
    local swap_num="${swap_size%[GgMm]}"
    local swap_unit="${swap_size: -1}"
    case "${swap_unit,,}" in
      g) required_mb=$(( required_mb + swap_num * 1024 )) ;;
      m) required_mb=$(( required_mb + swap_num )) ;;
    esac
  fi
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would check disk space (required: ${required_mb}M)."
    return 0
  fi

  # If a stale swapfile exists and will be removed, we can reclaim that space.
  local swapfile_mb=0
  if [[ -f /swapfile ]] && ! swapon --show --noheadings 2>/dev/null | grep -q '/swapfile'; then
    swapfile_mb="$(du -m /swapfile 2>/dev/null | cut -f1)" || swapfile_mb=0
  fi

  local avail_mb
  avail_mb="$(df -m / 2>/dev/null | awk 'NR==2 {print $4}')"
  if [[ -z "${avail_mb}" || ! "${avail_mb}" =~ ^[0-9]+$ ]]; then
    warn "Cannot determine available disk space; skipping pre-flight check."
    return 0
  fi

  # Add reclaimed swapfile space to available
  local effective_avail=$(( avail_mb + swapfile_mb ))

  if (( effective_avail < required_mb )); then
    die "Insufficient disk space: ${avail_mb}M available (+${swapfile_mb}M from stale swap), ${required_mb}M required (swap: ${swap_size} + 512M base)."
  fi
  log "Disk pre-flight: ${avail_mb}M available, ${required_mb}M required. OK."
}

detect_wan_iface() {
  if [[ -n "${WAN_IFACE}" ]]; then
    return 0
  fi

  WAN_IFACE="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i+1); exit }}')"
  [[ -n "${WAN_IFACE}" ]] || die "Unable to auto-detect WAN interface. Set --wan-iface."
}

ssh_session_safety_gate() {
  if [[ -z "${SSH_CONNECTION:-}" ]]; then
    return 0
  fi

  local src_ip
  src_ip="${SSH_CONNECTION%% *}"
  if [[ "${src_ip}" != 100.* && "${src_ip}" != fd7a:* ]] && ! is_true "${FORCE}"; then
    die "Current SSH source (${src_ip}) is not Tailscale-like; refusing to continue without --force."
  fi
}

ensure_packages() {
  local packages
  local missing=()
  packages=(
    curl
    ufw
    auditd
    audispd-plugins
    unattended-upgrades
    apt-listchanges
    openssh-server
    iptables
    fail2ban
  )

  for pkg in "${packages[@]}"; do
    if ! dpkg-query -W -f='${Status}' "${pkg}" 2>/dev/null | grep -q "install ok installed"; then
      missing+=("${pkg}")
    fi
  done

  if ((${#missing[@]} > 0)); then
    log "Installing required packages: ${missing[*]}"
    retry_apt_update
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing[@]}"
  fi
}

require_commands() {
  local commands=()
  commands+=(ip awk grep sed)

  if ! is_true "${DRY_RUN}"; then
    commands+=(sshd ufw iptables journalctl systemctl augenrules auditctl fail2ban-client)
  fi

  for cmd in "${commands[@]}"; do
    command -v "${cmd}" >/dev/null 2>&1 || die "Missing command: ${cmd}"
  done
}

retry_apt_update() {
  local attempts=3 delay=5 i
  for (( i = 1; i <= attempts; i++ )); do
    if run apt-get update; then
      return 0
    fi
    if (( i < attempts )); then
      log "apt-get update failed (attempt ${i}/${attempts}); retrying in ${delay}s..."
      sleep "${delay}"
    fi
  done
  die "apt-get update failed after ${attempts} attempts."
}

verify_tailscale_iface() {
  ip link show "${TAILSCALE_IFACE}" >/dev/null 2>&1 || die "Interface ${TAILSCALE_IFACE} not found. Refusing Tailscale-only SSH hardening."
}

warn_on_state_version_mismatch() {
  [[ -f "${STATE_FILE}" ]] || return 0

  local existing_version
  existing_version="$(grep -m1 '^script_version=' "${STATE_FILE}" | cut -d= -f2- || true)"
  if [[ -n "${existing_version}" && "${existing_version}" != "${SCRIPT_VERSION}" ]]; then
    warn "Existing hardening state found (v${existing_version}), re-running v${SCRIPT_VERSION}."
  fi
}

# Store detected Tailscale IP for split-horizon binding
DETECTED_TAILSCALE_IP=""

get_tailscale_ip() {
  if [[ -n "${DETECTED_TAILSCALE_IP}" ]]; then
    echo "${DETECTED_TAILSCALE_IP}"
    return 0
  fi

  command -v tailscale >/dev/null 2>&1 || return 1
  DETECTED_TAILSCALE_IP="$(tailscale ip -4 2>/dev/null)" || return 1
  echo "${DETECTED_TAILSCALE_IP}"
}

install_tailscale() {
  if command -v tailscale >/dev/null 2>&1; then
    log "Tailscale already installed."
    return 0
  fi

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would install Tailscale via official install script."
    return 0
  fi

  log "Installing Tailscale..."
  run curl -fsSL https://tailscale.com/install.sh | sh

  # Authenticate Tailscale
  if [[ -n "${TAILSCALE_AUTH_KEY}" ]]; then
    log "Authenticating Tailscale with provided auth key..."
    run tailscale up --ssh --authkey="${TAILSCALE_AUTH_KEY}"
  else
    log "Interactive Tailscale authentication required."
    log "Run: tailscale up --ssh"
    log "Waiting for Tailscale connection (timeout: 120s)..."

    local timeout=120
    local elapsed=0
    while ! ip link show "${TAILSCALE_IFACE}" >/dev/null 2>&1; do
      if (( elapsed >= timeout )); then
        die "Timeout waiting for Tailscale interface. Run 'tailscale up --ssh' manually and retry."
      fi
      sleep 2
      elapsed=$((elapsed + 2))
      log "Waiting for ${TAILSCALE_IFACE}... (${elapsed}s/${timeout}s)"
    done
  fi

  log "Tailscale installed and configured."
}

configure_coolify_binding_watchdog() {
  if ! is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    return 0
  fi

  write_file "${COOLIFY_BINDING_GUARD_SCRIPT}" "0750" "root" "root" <<'GUARD_EOF'
#!/usr/bin/env bash
# Coolify dashboard UFW binding guard.
# Verifies that UFW rules restricting the Coolify dashboard (port 8000), Soketi
# (port 6001), and terminal (port 6002) to the tailscale0 interface exist, and
# re-applies them if missing.
# Does NOT modify Coolify's .env — security is enforced entirely by UFW.
set -Euo pipefail

TAILSCALE_IFACE="tailscale0"
LOG_TAG="coolify-binding-guard"

log() { logger -t "${LOG_TAG}" -- "$*"; }

command -v ufw >/dev/null 2>&1 || { log "ufw not found; skipping."; exit 0; }

ufw_status="$(ufw status 2>/dev/null | head -1)" || true
[[ "${ufw_status}" == "Status: active" ]] || { log "UFW not active; skipping."; exit 0; }

changed=false

# Check and re-apply UFW rule for port 8000 on tailscale0
if ! ufw status | grep -q "8000.*on ${TAILSCALE_IFACE}"; then
  log "UFW rule for port 8000 on ${TAILSCALE_IFACE} missing — re-applying."
  ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 8000 \
    comment "coolify-hardening-dashboard-tailscale" 2>/dev/null || true
  changed=true
fi

# Check and re-apply UFW rule for port 6001 on tailscale0
if ! ufw status | grep -q "6001.*on ${TAILSCALE_IFACE}"; then
  log "UFW rule for port 6001 on ${TAILSCALE_IFACE} missing — re-applying."
  ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6001 \
    comment "coolify-hardening-soketi-tailscale" 2>/dev/null || true
  changed=true
fi

# Check and re-apply UFW rule for port 6002 on tailscale0
if ! ufw status | grep -q "6002.*on ${TAILSCALE_IFACE}"; then
  log "UFW rule for port 6002 on ${TAILSCALE_IFACE} missing — re-applying."
  ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6002 \
    comment "coolify-hardening-terminal-tailscale" 2>/dev/null || true
  changed=true
fi

if "${changed}"; then
  log "UFW rules re-applied for ports 8000, 6001, and 6002 on ${TAILSCALE_IFACE}."
else
  log "UFW rules for ports 8000, 6001, and 6002 on ${TAILSCALE_IFACE} are present — no action needed."
fi

# Verify public IP is NOT serving the dashboard
if command -v nc >/dev/null 2>&1; then
  public_ip="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')" || true
  tailscale_ip="$(tailscale ip -4 2>/dev/null)" || true
  if [[ -n "${public_ip}" && "${public_ip}" != "${tailscale_ip}" ]]; then
    if nc -z -w2 "${public_ip}" 8000 2>/dev/null; then
      log "WARN: Port 8000 still reachable on public IP ${public_ip} — check UFW rules."
    fi
  fi
fi
GUARD_EOF

  write_file "${COOLIFY_BINDING_GUARD_SERVICE}" "0644" "root" "root" <<'UNIT_EOF'
[Unit]
Description=Verify Coolify dashboard UFW rules on tailscale0 are present
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/coolify-binding-guard.sh
UNIT_EOF

  write_file "${COOLIFY_BINDING_GUARD_TIMER}" "0644" "root" "root" <<'TIMER_EOF'
[Unit]
Description=Periodically verify Coolify dashboard UFW rules on tailscale0

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
AccuracySec=30s

[Install]
WantedBy=timers.target
TIMER_EOF

  run systemctl daemon-reload
  run systemctl enable --now coolify-binding-guard.timer
  log "Coolify binding watchdog enabled (checks every 5 minutes)."
}

configure_coolify_binding() {
  if ! is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    return 0
  fi

  # Dashboard Tailscale restriction is enforced via UFW, not APP_PORT socket binding.
  # Coolify's docker-compose.prod.yml uses APP_PORT in both ports: and expose: directives;
  # expose: requires a plain port number, so IP:port format is incompatible and breaks
  # Coolify container startup. UFW default-deny + explicit allow on tailscale0 provides
  # equivalent defense-in-depth without touching Coolify's configuration.
  #
  # UFW rules for 8000/6001 on tailscale0 are already applied by configure_ufw(); this
  # function verifies they exist and re-applies them idempotently.

  log "Verifying Coolify dashboard UFW rules on ${TAILSCALE_IFACE}..."

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would verify UFW rules for ports 8000, 6001, and 6002 on ${TAILSCALE_IFACE}"
    log "DRY-RUN: would verify port 8000 is listening and not reachable on public IP"
    return 0
  fi

  if command -v ufw >/dev/null 2>&1; then
    # Idempotent — ufw silently skips duplicate rules
    local ufw_result ufw_ok=true
    ufw_result="$(ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 8000 \
      comment "coolify-hardening-dashboard-tailscale" 2>&1)" || {
      warn "UFW rule for port 8000 on ${TAILSCALE_IFACE} failed: ${ufw_result}"
      ufw_ok=false
    }
    ufw_result="$(ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6001 \
      comment "coolify-hardening-soketi-tailscale" 2>&1)" || {
      warn "UFW rule for port 6001 on ${TAILSCALE_IFACE} failed: ${ufw_result}"
      ufw_ok=false
    }
    ufw_result="$(ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6002 \
      comment "coolify-hardening-terminal-tailscale" 2>&1)" || {
      warn "UFW rule for port 6002 on ${TAILSCALE_IFACE} failed: ${ufw_result}"
      ufw_ok=false
    }
    is_true "${ufw_ok}" && log "UFW rules verified for ports 8000, 6001, and 6002 on ${TAILSCALE_IFACE}."
  else
    warn "ufw not found — skipping UFW rule verification."
  fi

  # Wait for Coolify to bind port 8000 (up to 30s)
  log "Waiting for Coolify to bind port 8000 (up to 30s)..."
  local i
  for (( i = 1; i <= 6; i++ )); do
    if ss -tlnp 2>/dev/null | grep -q ':8000 '; then
      break
    fi
    sleep 5
  done

  # Verify port 8000 is listening
  local bound_8000
  bound_8000="$(ss -tlnp 2>/dev/null | grep ':8000 ' || true)"
  if [[ -n "${bound_8000}" ]]; then
    log "PASS: Port 8000 is listening"
  else
    warn "Port 8000 not yet listening. Check: ss -tlnp | grep 8000"
  fi

  # Verify public IP is NOT serving the dashboard (UFW should block it)
  if command -v nc >/dev/null 2>&1; then
    local public_ip
    public_ip="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')"
    local tailscale_ip
    tailscale_ip="$(get_tailscale_ip)" || true
    if [[ -n "${public_ip}" && "${public_ip}" != "${tailscale_ip}" ]]; then
      if nc -z -w2 "${public_ip}" 8000 2>/dev/null; then
        warn "Port 8000 still appears reachable on public IP ${public_ip}. Check UFW rules."
      else
        log "PASS: Port 8000 not reachable on public IP ${public_ip}"
      fi
    fi
  fi

  log "Coolify dashboard UFW restriction verified."
}

ensure_timesync() {
  if ! is_true "${DRY_RUN}"; then
    local ntp_active
    ntp_active="$(timedatectl show --property=NTP --value 2>/dev/null || echo "n/a")"
    if [[ "${ntp_active}" != "yes" ]]; then
      if run timedatectl set-ntp true; then
        log "NTP synchronization enabled."
      else
        warn "Could not enable NTP (timedatectl set-ntp failed). Verify manually."
      fi
    else
      log "NTP synchronization already active."
    fi
    local i
    for (( i = 1; i <= 6; i++ )); do
      local synced
      synced="$(timedatectl show --property=NTPSynchronized --value 2>/dev/null || echo "n/a")"
      if [[ "${synced}" == "yes" ]]; then
        log "NTP synchronized."
        return 0
      fi
      log "Waiting for NTP synchronization (${i}/6)..."
      sleep 5
    done
    warn "NTP not synchronized after 30s; continuing. Verify with: timedatectl status"
  else
    log "DRY-RUN: would verify NTP synchronization."
  fi
}

configure_swap() {
  local swap_size="${SWAP_SIZE:-2G}"
  [[ "${swap_size}" == "0" ]] && { log "Swap creation disabled (--swap-size 0)."; return 0; }

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would configure ${swap_size} swap file at /swapfile."
    return 0
  fi

  if swapon --show --noheadings | grep -q .; then
    log "Swap already active. Skipping."
    return 0
  fi

  local swap_file="/swapfile"
  if [[ -f "${swap_file}" ]]; then
    log "Stale ${swap_file} found (not active in swapon); removing."
    run rm -f "${swap_file}"
  fi
  run fallocate -l "${swap_size}" "${swap_file}"
  run chmod 600 "${swap_file}"
  run mkswap "${swap_file}"
  run swapon "${swap_file}"

  if ! grep -qxF "${swap_file} none swap sw 0 0" /etc/fstab; then
    echo "${swap_file} none swap sw 0 0" >> /etc/fstab
  fi

  log "Swap configured: ${swap_size} at ${swap_file}."
}

disable_unused_services() {
  local services=(rpcbind avahi-daemon cups cups-browsed)
  local unit
  for svc in "${services[@]}"; do
    for unit in "${svc}.service" "${svc}.socket"; do
      if systemctl list-unit-files --no-legend "${unit}" 2>/dev/null | grep -q "${unit}"; then
        log "Disabling and masking ${unit}"
        run systemctl disable --now "${unit}" 2>/dev/null || true
        run systemctl mask "${unit}" 2>/dev/null || true
      fi
    done
  done
}

configure_sysctl() {
  # Check if BBR kernel module is available
  local bbr_available="false"
  if modinfo tcp_bbr &>/dev/null; then
    bbr_available="true"
  fi

  {
    cat <<'SYSCTL_BASE'
# Managed by bootstrap hardening — Coolify/Docker safe
net.ipv4.ip_forward = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv6.conf.default.accept_source_route = 0
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.icmp_ignore_bogus_error_responses = 1
net.ipv4.conf.all.rp_filter = 2
net.ipv4.conf.default.rp_filter = 2
# SYN flood hardening
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
fs.suid_dumpable = 0
kernel.unprivileged_bpf_disabled = 1
kernel.kexec_load_disabled = 1
kernel.sysrq = 4
kernel.randomize_va_space = 2
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
kernel.yama.ptrace_scope = 1
kernel.perf_event_paranoid = 3
SYSCTL_BASE

    if [[ "${bbr_available}" == "true" ]]; then
      cat <<'SYSCTL_BBR'
# TCP performance: BBR congestion control
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
SYSCTL_BBR
    fi

    if [[ "${SWAP_SIZE:-2G}" != "0" ]]; then
      cat <<'SYSCTL_SWAP'
# Swap tuning: prefer RAM, use swap only under pressure
vm.swappiness = 10
SYSCTL_SWAP
    fi
  } | write_file "${SYSCTL_DROPIN_FILE}" "0644" "root" "root"

  if [[ "${bbr_available}" == "false" ]]; then
    warn "BBR not available: kernel module tcp_bbr not found. Using default congestion control."
  fi

  run sysctl --system

  if ! is_true "${DRY_RUN}"; then
    local syncookies ip_forward
    syncookies="$(sysctl -n net.ipv4.tcp_syncookies 2>/dev/null || echo "?")"
    ip_forward="$(sysctl -n net.ipv4.ip_forward 2>/dev/null || echo "?")"
    [[ "${syncookies}" == "1" ]] || die "Post-sysctl check failed: tcp_syncookies is ${syncookies}, expected 1."
    [[ "${ip_forward}" == "1" ]] || die "Post-sysctl check failed: ip_forward is ${ip_forward}, expected 1 (Docker requires this)."

    if [[ "${bbr_available}" == "true" ]]; then
      local bbr
      bbr="$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null || echo "?")"
      [[ "${bbr}" == "bbr" ]] || warn "BBR not active: ${bbr} (kernel module tcp_bbr may be unavailable)."
    fi
  fi
}

configure_fail2ban() {
  write_file "${FAIL2BAN_JAIL_FILE}" "0644" "root" "root" <<EOF
# Managed by bootstrap hardening
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5
banaction = ufw
ignoreip = 127.0.0.1/8 ::1 ${TAILSCALE_CIDR}

[sshd]
enabled = true
port = ${SSH_PORT}
backend = systemd
maxretry = 3
bantime = 1h
EOF

  run systemctl enable --now fail2ban
  if ! is_true "${DRY_RUN}"; then
    run systemctl restart fail2ban
  fi
}

configure_banner() {
  write_file "/etc/issue.net" "0644" "root" "root" <<'EOF'
***************************************************************************
                   AUTHORIZED ACCESS ONLY
This system is for authorized use only. All activity may be monitored
and reported. Unauthorized access is prohibited and may be subject to
criminal and civil penalties.
***************************************************************************
EOF
}

ensure_admin_access() {
  local home_dir
  local ssh_dir
  local auth_file
  local user_exists="false"

  if id "${ADMIN_USER}" >/dev/null 2>&1; then
    user_exists="true"
    log "Admin user exists: ${ADMIN_USER}"
  else
    run useradd -m -s /bin/bash -G sudo "${ADMIN_USER}"
  fi

  if [[ "${user_exists}" == "true" ]] && ! id -nG "${ADMIN_USER}" | tr ' ' '\n' | grep -qx "sudo"; then
    run usermod -aG sudo "${ADMIN_USER}"
  fi

  # Configure passwordless sudo for admin user
  # This is required because the admin user has no password set,
  # but sudo requires password by default, blocking all admin operations.
  local sudoers_file="/etc/sudoers.d/${ADMIN_USER}"
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would create ${sudoers_file} with passwordless sudo for ${ADMIN_USER}"
  else
    echo "${ADMIN_USER} ALL=(ALL) NOPASSWD: ALL" > "${sudoers_file}"
    chmod 440 "${sudoers_file}"
    # Validate sudoers syntax before committing
    if ! visudo -c -f "${sudoers_file}" >/dev/null 2>&1; then
      rm -f "${sudoers_file}"
      die "Failed to create valid sudoers file for ${ADMIN_USER}"
    fi
    log "Configured passwordless sudo for ${ADMIN_USER}"
  fi

  if is_true "${DRY_RUN}" && [[ "${user_exists}" == "false" ]]; then
    log "DRY-RUN: would create /home/${ADMIN_USER}/.ssh/authorized_keys with provided key."
    return 0
  fi

  home_dir="$(getent passwd "${ADMIN_USER}" | cut -d: -f6)"
  [[ -n "${home_dir}" ]] || die "Unable to resolve home directory for ${ADMIN_USER}."
  ssh_dir="${home_dir}/.ssh"
  auth_file="${ssh_dir}/authorized_keys"

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: ensure ${auth_file} contains provided key."
    return 0
  fi

  install -d -m 0700 -o "${ADMIN_USER}" -g "${ADMIN_USER}" "${ssh_dir}"
  touch "${auth_file}"
  chown "${ADMIN_USER}:${ADMIN_USER}" "${auth_file}"
  chmod 0600 "${auth_file}"

  if ! grep -qxF "${ADMIN_PUBKEY}" "${auth_file}"; then
    printf '%s\n' "${ADMIN_PUBKEY}" >> "${auth_file}"
  fi
}

restore_ssh_dropin() {
  local backup="$1"
  if is_true "${DRY_RUN}"; then
    return 0
  fi
  if [[ -n "${backup}" && -f "${backup}" ]]; then
    cp -a "${backup}" "${SSH_DROPIN_FILE}"
  else
    rm -f "${SSH_DROPIN_FILE}"
  fi
}

assert_sshd_effective() {
  local effective="$1"

  grep -qE "^port ${SSH_PORT}$" <<< "${effective}" || return 1
  grep -q "^permitrootlogin no$" <<< "${effective}" || return 1
  grep -q "^passwordauthentication no$" <<< "${effective}" || return 1
  grep -q "^kbdinteractiveauthentication no$" <<< "${effective}" || return 1
  grep -q "^pubkeyauthentication yes$" <<< "${effective}" || return 1
  grep -q "^authenticationmethods publickey$" <<< "${effective}" || return 1
  grep -qE "^allowusers .*\\b${ADMIN_USER}\\b" <<< "${effective}" || return 1
  grep -q "^permitemptypasswords no$" <<< "${effective}" || return 1
  grep -q "^compression no$" <<< "${effective}" || return 1
  grep -q "chacha20-poly1305@openssh.com" <<< "${effective}" || return 1
  grep -q "hmac-sha2-512-etm@openssh.com" <<< "${effective}" || return 1
  grep -q "sntrup761x25519-sha512@openssh.com" <<< "${effective}" || return 1
  grep -q "hostkeyalgorithms .*ssh-ed25519" <<< "${effective}" || return 1
}

assert_sshd_match_localhost() {
  local effective="$1"

  # OpenSSH outputs "prohibit-password" or its legacy synonym "without-password"
  grep -qE "^permitrootlogin (prohibit-password|without-password)$" <<< "${effective}" || return 1
  grep -qE "^allowusers .*\\broot\\b" <<< "${effective}" || return 1
  grep -qE "^allowusers .*\\b${ADMIN_USER}\\b" <<< "${effective}" || return 1
}

reload_ssh_service() {
  local units
  local has_ssh="false"
  local has_sshd="false"

  if ! systemctl list-unit-files --type=service --no-legend >/dev/null 2>&1; then
    return 1
  fi

  units="$(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}')"
  grep -qx "ssh.service" <<< "${units}" && has_ssh="true" || true
  grep -qx "sshd.service" <<< "${units}" && has_sshd="true" || true

  if [[ "${has_ssh}" == "true" ]]; then
    if ! systemctl is-active --quiet ssh; then
      systemctl start ssh || return 1
    fi
    systemctl reload ssh || systemctl restart ssh || return 1
    return 0
  fi

  if [[ "${has_sshd}" == "true" ]]; then
    if ! systemctl is-active --quiet sshd; then
      systemctl start sshd || return 1
    fi
    systemctl reload sshd || systemctl restart sshd || return 1
    return 0
  fi

  return 1
}

configure_ssh() {
  local backup=""
  local effective=""

  if ! is_true "${DRY_RUN}" && [[ ! -d /run/sshd ]]; then
    install -d -m 0755 /run/sshd
  fi

  if [[ -f "${SSH_DROPIN_FILE}" ]] && ! is_true "${DRY_RUN}"; then
    backup="${SSH_DROPIN_FILE}.bak.$(date +%s)"
    cp -a "${SSH_DROPIN_FILE}" "${backup}"
  fi

  write_file "${SSH_DROPIN_FILE}" "0644" "root" "root" <<EOF
# Managed by ${SCRIPT_NAME}
Port ${SSH_PORT}
PermitRootLogin no
PasswordAuthentication no
PermitEmptyPasswords no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AuthenticationMethods publickey
AllowUsers ${ADMIN_USER}
X11Forwarding no
AllowAgentForwarding no
AllowTcpForwarding no
Compression no
MaxAuthTries 3
LoginGraceTime 30
ClientAliveInterval 300
ClientAliveCountMax 2
Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com
MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com
KexAlgorithms sntrup761x25519-sha512@openssh.com,curve25519-sha256,curve25519-sha256@libssh.org
HostKeyAlgorithms ssh-ed25519,rsa-sha2-512,rsa-sha2-256
Banner /etc/issue.net

# Coolify connects to its own host as root via localhost / Docker bridge.
# Allow key-only root login from loopback and Docker address pool (10.0.0.0/8
# configured in daemon.json) and RFC 1918 172.16/12 bridges.
Match Address 127.0.0.1,::1,172.16.0.0/12,10.0.0.0/8
    PermitRootLogin prohibit-password
    AllowUsers ${ADMIN_USER} root
EOF

  if is_true "${DRY_RUN}"; then
    return 0
  fi

  if ! sshd -t; then
    restore_ssh_dropin "${backup}"
    die "sshd -t failed after writing SSH hardening drop-in."
  fi

  effective="$(sshd -T 2>/dev/null || true)"
  if ! assert_sshd_effective "${effective}"; then
    restore_ssh_dropin "${backup}"
    die "sshd -T did not match expected hardened values."
  fi

  local match_effective
  match_effective="$(sshd -T -C addr=127.0.0.1,user=root,host=localhost,laddr=127.0.0.1 2>/dev/null || true)"
  if ! assert_sshd_match_localhost "${match_effective}"; then
    restore_ssh_dropin "${backup}"
    die "sshd -T -C (localhost Match block) did not match expected values."
  fi

  if ! reload_ssh_service; then
    restore_ssh_dropin "${backup}"
    die "Failed to reload SSH service."
  fi
}

configure_ufw() {
  run ufw --force reset
  run ufw default deny incoming
  run ufw default allow outgoing
  run ufw default deny routed

  run ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port "${SSH_PORT}" comment "coolify-hardening-ssh-tailscale"

  # Allow Coolify to SSH to the host from inside its Docker container.
  # Docker uses 10.0.0.0/8 as address pool (set in daemon.json). The container
  # connects to host.docker.internal (= Docker bridge gateway) on port 22 to manage
  # "This Machine". Key-only auth; the sshd Match block above restricts login to root.
  run ufw allow in from 10.0.0.0/8 to any port "${SSH_PORT}" comment "coolify-hardening-ssh-docker-bridge"

  # Allow Coolify dashboard, Soketi, and terminal on Tailscale interface only.
  # UFW default deny blocks external access; these rules make them reachable via VPN.
  # 8000 = dashboard, 6001 = Soketi real-time, 6002 = terminal (required since beta.336)
  run ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 8000 comment "coolify-hardening-dashboard-tailscale"
  run ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6001 comment "coolify-hardening-soketi-tailscale"
  run ufw allow in on "${TAILSCALE_IFACE}" proto tcp to any port 6002 comment "coolify-hardening-terminal-tailscale"

  if is_true "${TUNNEL_MODE}"; then
    log "Tunnel mode: skipping WAN 80/443 UFW rules (traffic arrives via outbound tunnel)."
  else
    run ufw allow in on "${WAN_IFACE}" proto tcp to any port 80 comment "coolify-hardening-http"
    run ufw allow in on "${WAN_IFACE}" proto tcp to any port 443 comment "coolify-hardening-https"
  fi

  run ufw allow in on "${WAN_IFACE}" proto udp to any port 41641 comment "coolify-hardening-tailscale-direct"

  # ICMP is allowed via UFW's default before.rules (ufw allow proto icmp is not supported)

  run ufw --force enable
}

install_docker_user_assets() {
  write_file "${DOCKER_USER_SCRIPT}" "0750" "root" "root" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

WAN_IFACE="${WAN_IFACE:-${1:-}}"
TAILSCALE_IFACE="${TAILSCALE_IFACE:-tailscale0}"
TUNNEL_MODE="${TUNNEL_MODE:-false}"

if [[ -z "${WAN_IFACE}" ]]; then
  echo "WAN_IFACE is required." >&2
  exit 1
fi

if ! command -v iptables >/dev/null 2>&1; then
  echo "iptables is required." >&2
  exit 1
fi

is_true() {
  case "${1,,}" in
    1|true|yes|y|on) return 0 ;;
    *) return 1 ;;
  esac
}

ipt() {
  iptables -w "$@"
}

# --- IPv4 ---

ipt -t filter -N DOCKER-USER 2>/dev/null || true
if ! ipt -t filter -C FORWARD -j DOCKER-USER >/dev/null 2>&1; then
  ipt -t filter -I FORWARD 1 -j DOCKER-USER
fi

while true; do
  line_no="$(ipt -t filter -L DOCKER-USER --line-numbers -n | awk '/coolify-hardening-/ { print $1; exit }')"
  [[ -n "${line_no}" ]] || break
  ipt -t filter -D DOCKER-USER "${line_no}" || true
done

ipt -t filter -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -m comment --comment "coolify-hardening-estab" -j RETURN
ipt -t filter -A DOCKER-USER -i "${TAILSCALE_IFACE}" -m comment --comment "coolify-hardening-tailscale" -j ACCEPT
ipt -t filter -A DOCKER-USER -i docker0 -m comment --comment "coolify-hardening-bridge-docker0" -j RETURN
ipt -t filter -A DOCKER-USER -i "br+" -m comment --comment "coolify-hardening-bridge-user" -j RETURN
if ! is_true "${TUNNEL_MODE}"; then
  ipt -t filter -A DOCKER-USER -i "${WAN_IFACE}" -p tcp -m multiport --dports 80,443 -m comment --comment "coolify-hardening-wan-web" -j ACCEPT
fi
ipt -t filter -A DOCKER-USER -i "${WAN_IFACE}" -m comment --comment "coolify-hardening-wan-drop" -j DROP
ipt -t filter -A DOCKER-USER -m comment --comment "coolify-hardening-return" -j RETURN

# --- IPv6 ---

if command -v ip6tables >/dev/null 2>&1; then
  ipt6() {
    ip6tables -w "$@"
  }

  ipt6 -t filter -N DOCKER-USER 2>/dev/null || true
  if ! ipt6 -t filter -C FORWARD -j DOCKER-USER >/dev/null 2>&1; then
    ipt6 -t filter -I FORWARD 1 -j DOCKER-USER
  fi

  while true; do
    line_no="$(ipt6 -t filter -L DOCKER-USER --line-numbers -n | awk '/coolify-hardening-/ { print $1; exit }')"
    [[ -n "${line_no}" ]] || break
    ipt6 -t filter -D DOCKER-USER "${line_no}" || true
  done

  ipt6 -t filter -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -m comment --comment "coolify-hardening-estab6" -j RETURN
  ipt6 -t filter -A DOCKER-USER -i "${TAILSCALE_IFACE}" -m comment --comment "coolify-hardening-tailscale6" -j ACCEPT
  if ! is_true "${TUNNEL_MODE}"; then
    ipt6 -t filter -A DOCKER-USER -i "${WAN_IFACE}" -p tcp -m multiport --dports 80,443 -m comment --comment "coolify-hardening-wan-web6" -j ACCEPT
  fi
  ipt6 -t filter -A DOCKER-USER -i "${WAN_IFACE}" -m comment --comment "coolify-hardening-wan-drop6" -j DROP
  ipt6 -t filter -A DOCKER-USER -m comment --comment "coolify-hardening-return6" -j RETURN
else
  echo "ip6tables not available; skipping IPv6 DOCKER-USER rules." >&2
fi
EOF

  write_file "${DOCKER_USER_ENV_FILE}" "0644" "root" "root" <<EOF
WAN_IFACE=${WAN_IFACE}
TAILSCALE_IFACE=${TAILSCALE_IFACE}
TUNNEL_MODE=${TUNNEL_MODE}
EOF

  write_file "${DOCKER_USER_UNIT_FILE}" "0644" "root" "root" <<EOF
[Unit]
Description=Apply managed DOCKER-USER hardening rules
After=docker.service
Requires=docker.service
PartOf=docker.service

[Service]
Type=oneshot
EnvironmentFile=${DOCKER_USER_ENV_FILE}
ExecStart=${DOCKER_USER_SCRIPT}
RemainAfterExit=yes

[Install]
WantedBy=docker.service
EOF
}

detect_docker() {
  if command -v docker >/dev/null 2>&1; then
    DOCKER_PRESENT="true"
    log "Docker detected."
  else
    log "Docker not detected."
  fi
}

configure_docker_user() {
  install_docker_user_assets

  # Remove stale WantedBy=multi-user.target symlinks from prior script versions
  if ! is_true "${DRY_RUN}"; then
    systemctl disable docker-user-hardening.service 2>/dev/null || true
  fi
  run systemctl daemon-reload
  run systemctl enable docker-user-hardening.service

  if [[ "${DOCKER_PRESENT}" == "true" ]]; then
    # Docker CLI may exist while docker.service is not yet installed/available.
    if systemctl list-unit-files --type=service 2>/dev/null | awk '{print $1}' | grep -qx 'docker.service'; then
      run systemctl start docker-user-hardening.service || warn "docker-user-hardening.service could not be started; start after Docker is ready."
      if systemctl is-active --quiet docker-user-hardening.service 2>/dev/null; then
        DOCKER_RULES_APPLIED="true"
      fi
    else
      warn "Docker CLI detected but docker.service is not present; DOCKER-USER start is deferred."
    fi
  else
    warn "Docker not detected; DOCKER-USER unit installed and enabled, but start is deferred."
  fi
}

DOCKER_DAEMON_JSON="/etc/docker/daemon.json"

configure_docker_daemon() {
  # Required settings for hardening
  # Note: log-driver uses json-file (same as Coolify) for compatibility.
  # Hardening owns: log-driver, log-opts, live-restore. Coolify may add: default-address-pools.
  local required_settings='{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"},"live-restore":true}'

  if [[ "${DOCKER_PRESENT}" != "true" ]]; then
    log "Docker not present; skipping daemon.json creation (will be needed post-install)."
    return 0
  fi

  if [[ -f "${DOCKER_DAEMON_JSON}" ]]; then
    # File exists - merge our required settings with existing config
    log "Merging hardening settings into existing ${DOCKER_DAEMON_JSON}"

    if is_true "${DRY_RUN}"; then
      log "DRY-RUN: would merge hardening settings into ${DOCKER_DAEMON_JSON}"
      return 0
    fi

    # Check if jq is available for proper JSON merging
    if ! command -v jq >/dev/null 2>&1; then
      warn "jq not installed; installing for JSON merge..."
      retry_apt_update
      run env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends jq
    fi

    # Backup existing config
    local backup="${DOCKER_DAEMON_JSON}.bak.$(date +%s)"
    cp -a "${DOCKER_DAEMON_JSON}" "${backup}"
    log "Backed up ${DOCKER_DAEMON_JSON} to ${backup}"

    # Merge: our settings take precedence but preserve other existing settings
    local merged required_tmp
    required_tmp="$(mktemp)"
    echo "${required_settings}" > "${required_tmp}"
    merged="$(jq -s '.[0] * .[1]' "${DOCKER_DAEMON_JSON}" "${required_tmp}" 2>/dev/null)"
    rm -f "${required_tmp}"

    if [[ -z "${merged}" ]]; then
      die "Failed to merge ${DOCKER_DAEMON_JSON} with jq; cannot safely apply hardening settings."
    else
      echo "${merged}" > "${DOCKER_DAEMON_JSON}"
      chmod 0644 "${DOCKER_DAEMON_JSON}"
    fi

    if systemctl is-active --quiet docker; then
      DOCKER_DAEMON_NEEDS_RESTART="true"
      log "Docker daemon.json updated; restart deferred until after DOCKER-USER rules are applied."
    fi

    log "Docker daemon.json updated with hardening settings."
    return 0
  fi

  # File doesn't exist - create it
  # Note: log-driver uses json-file (same as Coolify) for compatibility.
  # Hardening owns: log-driver, log-opts, live-restore. Coolify may add: default-address-pools.
  write_file "${DOCKER_DAEMON_JSON}" "0644" "root" "root" <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  },
  "live-restore": true
}
EOF

  log "Docker daemon.json written with log rotation (json-file driver, 10m x 3) and live-restore."
}

configure_journald() {
  write_file "${JOURNALD_DROPIN_FILE}" "0644" "root" "root" <<EOF
[Journal]
Storage=persistent
Compress=yes
SystemMaxUse=${JOURNAL_MAX_USE}
MaxRetentionSec=${JOURNAL_RETENTION}
EOF
  run systemctl restart systemd-journald
}

build_audit_rules() {
  cat <<'EOF'
# Managed by bootstrap hardening
-w /etc/passwd -p wa -k identity
-w /etc/shadow -p wa -k identity
-w /etc/group -p wa -k identity
-w /etc/gshadow -p wa -k identity
-w /etc/ssh/sshd_config -p wa -k sshd-config
-w /etc/ssh/sshd_config.d/ -p wa -k sshd-config
-w /etc/localtime -p wa -k time-change
-a always,exit -F arch=b64 -S adjtimex,settimeofday,clock_settime -k time-change
-a always,exit -F arch=b32 -S adjtimex,settimeofday,clock_settime -k time-change
-a always,exit -F arch=b64 -S sethostname,setdomainname -k system-locale
-a always,exit -F arch=b32 -S sethostname,setdomainname -k system-locale
-w /etc/sudoers -p wa -k sudoers-change
-w /etc/sudoers.d/ -p wa -k sudoers-change
EOF

  local bin
  for bin in /usr/bin/docker /usr/bin/dockerd /usr/bin/containerd; do
    if [[ -e "${bin}" ]]; then
      printf -- "-w %s -p x -k container-runtime\n" "${bin}"
    fi
  done

  local path
  for path in /var/run/docker.sock /etc/docker/; do
    if [[ -e "${path}" ]]; then
      printf -- "-w %s -p wa -k docker-config\n" "${path}"
    fi
  done
}

configure_auditd() {
  local tmp
  tmp="$(mktemp)"
  build_audit_rules > "${tmp}"

  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: write ${AUDIT_RULES_FILE}"
    rm -f "${tmp}"
  else
    install -d -m 0755 "$(dirname "${AUDIT_RULES_FILE}")"
    install -m 0640 -o root -g root "${tmp}" "${AUDIT_RULES_FILE}"
    rm -f "${tmp}"
  fi

  run systemctl enable --now auditd || warn "auditd could not be started (container/kernel limitation); rules file written."
  run augenrules --load
}

configure_unattended_upgrades() {
  local reboot_bool
  reboot_bool="false"
  if is_true "${ENABLE_AUTO_REBOOT}"; then
    reboot_bool="true"
  fi

  write_file "${APT_AUTO_FILE}" "0644" "root" "root" <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

  write_file "${APT_LOCAL_FILE}" "0644" "root" "root" <<EOF
Unattended-Upgrade::Origins-Pattern {
    "origin=Ubuntu,codename=\${distro_codename}-security,label=Ubuntu";
    "origin=Ubuntu,codename=\${distro_codename}-updates,label=Ubuntu";
    "origin=Docker,label=Docker CE";
};
Unattended-Upgrade::MinimalSteps "true";
Unattended-Upgrade::Automatic-Reboot "${reboot_bool}";
Unattended-Upgrade::Automatic-Reboot-Time "${AUTO_REBOOT_TIME}";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Remove-New-Unused-Dependencies "true";
$(if [[ -n "${UPGRADE_MAIL}" ]]; then
  printf 'Unattended-Upgrade::Mail "%s";\n' "${UPGRADE_MAIL}"
  printf 'Unattended-Upgrade::MailReport "only-on-error";\n'
fi)
EOF

  run systemctl enable --now apt-daily.timer apt-daily-upgrade.timer
  if ! is_true "${DRY_RUN}"; then
    unattended-upgrade --dry-run --debug >/tmp/unattended-upgrade-dryrun.log 2>&1 || warn "unattended-upgrade dry-run returned non-zero; see /tmp/unattended-upgrade-dryrun.log"
  fi
}

bool_cmd() {
  if "$@" >/dev/null 2>&1; then
    echo "true"
  else
    echo "false"
  fi
}

run_post_checks() {
  if is_true "${DRY_RUN}"; then
    log "Dry-run complete; post-apply checks skipped."
    return 0
  fi

  local ssh_effective
  ssh_effective="$(sshd -T 2>/dev/null || true)"
  assert_sshd_effective "${ssh_effective}" || die "Post-check failed: sshd effective settings do not match expected hardening."

  local ssh_match_local
  ssh_match_local="$(sshd -T -C addr=127.0.0.1,user=root,host=localhost,laddr=127.0.0.1 2>/dev/null || true)"
  assert_sshd_match_localhost "${ssh_match_local}" || die "Post-check failed: SSH Match block for localhost/Docker root access not effective."

  local ssh_match_external
  ssh_match_external="$(sshd -T -C addr=203.0.113.1,user=root,host=example.com,laddr=0.0.0.0 2>/dev/null || true)"
  if grep -qE "^permitrootlogin (prohibit-password|without-password|yes)$" <<< "${ssh_match_external}"; then
    die "Post-check failed: root login permitted from external address (Match block leak)."
  fi

  ufw status | grep -q "^Status: active$" || die "Post-check failed: UFW is not active."
  ufw status verbose | grep -qE "${SSH_PORT}/tcp.*on ${TAILSCALE_IFACE}.*ALLOW IN" || die "Post-check failed: SSH allow rule on ${TAILSCALE_IFACE} missing."
  if ufw status verbose | grep -qE "${SSH_PORT}/tcp.*on ${WAN_IFACE}.*ALLOW IN"; then
    die "Post-check failed: SSH appears allowed on WAN interface ${WAN_IFACE}."
  fi

  if is_true "${TUNNEL_MODE}"; then
    if ufw status verbose | grep -qE "80/tcp.*on ${WAN_IFACE}.*ALLOW IN"; then
      die "Post-check failed: tunnel-mode is active but WAN port 80 UFW rule exists."
    fi
    if ufw status verbose | grep -qE "443/tcp.*on ${WAN_IFACE}.*ALLOW IN"; then
      die "Post-check failed: tunnel-mode is active but WAN port 443 UFW rule exists."
    fi
  fi

  if [[ "${DOCKER_PRESENT}" == "true" ]]; then
    iptables -t filter -S DOCKER-USER | grep -q "coolify-hardening-wan-drop" || die "Post-check failed: DOCKER-USER IPv4 drop rule missing."
    iptables -t filter -S DOCKER-USER | grep -q "coolify-hardening-bridge-docker0" || die "Post-check failed: DOCKER-USER bridge-docker0 rule missing."
    if is_true "${TUNNEL_MODE}"; then
      if iptables -t filter -S DOCKER-USER | grep -q "coolify-hardening-wan-web"; then
        die "Post-check failed: tunnel-mode is active but DOCKER-USER wan-web ACCEPT rule exists."
      fi
    fi
    if command -v ip6tables >/dev/null 2>&1; then
      ip6tables -t filter -S DOCKER-USER 2>/dev/null | grep -q "coolify-hardening-wan-drop6" || die "Post-check failed: DOCKER-USER IPv6 drop rule missing."
    fi
  fi

  if [[ "${DOCKER_PRESENT}" == "true" && -f "${DOCKER_DAEMON_JSON}" ]]; then
    grep -q '"log-driver"' "${DOCKER_DAEMON_JSON}" || warn "Post-check: Docker daemon.json exists but log-driver not configured."
    grep -q '"live-restore"' "${DOCKER_DAEMON_JSON}" || warn "Post-check: Docker daemon.json exists but live-restore not configured."
  fi

  grep -q "^Storage=persistent$" "${JOURNALD_DROPIN_FILE}" || die "Post-check failed: journald persistence drop-in missing."
  { auditctl -l 2>/dev/null || cat "${AUDIT_RULES_FILE}"; } | grep -q "identity" \
    || die "Post-check failed: audit rules not loaded."
  { auditctl -l 2>/dev/null || cat "${AUDIT_RULES_FILE}"; } | grep -q "sudoers-change" \
    || die "Post-check failed: sudoers audit rules not loaded."
  grep -q 'APT::Periodic::Unattended-Upgrade "1";' "${APT_AUTO_FILE}" || die "Post-check failed: unattended-upgrades periodic config missing."

  local syncookies ip_forward
  syncookies="$(sysctl -n net.ipv4.tcp_syncookies 2>/dev/null || echo "?")"
  ip_forward="$(sysctl -n net.ipv4.ip_forward 2>/dev/null || echo "?")"
  [[ "${syncookies}" == "1" ]] || die "Post-check failed: tcp_syncookies is ${syncookies}, expected 1."
  [[ "${ip_forward}" == "1" ]] || die "Post-check failed: ip_forward is ${ip_forward}, expected 1."

  systemctl is-active --quiet fail2ban || die "Post-check failed: fail2ban is not active."

  [[ -f /etc/issue.net ]] || die "Post-check failed: /etc/issue.net missing."

  journalctl --disk-usage || true

  if command -v aa-status >/dev/null 2>&1; then
    if ! aa-status --enabled 2>/dev/null; then
      warn "AppArmor is installed but not enabled. Ubuntu 24.04 should have it active by default."
    fi
  else
    warn "aa-status not found; cannot verify AppArmor status."
  fi

  # Dashboard UFW restriction verification (UFW-based; does not check socket binding)
  if is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    log "Verifying Coolify dashboard UFW rules on ${TAILSCALE_IFACE}..."
    if command -v ufw >/dev/null 2>&1; then
      if ufw status | grep -q "8000.*on ${TAILSCALE_IFACE}"; then
        log "PASS: UFW rule for port 8000 on ${TAILSCALE_IFACE} is present"
      else
        warn "UFW rule for port 8000 on ${TAILSCALE_IFACE} not found. Check: ufw status"
      fi
      if ufw status | grep -q "6001.*on ${TAILSCALE_IFACE}"; then
        log "PASS: UFW rule for port 6001 on ${TAILSCALE_IFACE} is present"
      else
        warn "UFW rule for port 6001 on ${TAILSCALE_IFACE} not found. Check: ufw status"
      fi
      if ufw status | grep -q "6002.*on ${TAILSCALE_IFACE}"; then
        log "PASS: UFW rule for port 6002 on ${TAILSCALE_IFACE} is present"
      else
        warn "UFW rule for port 6002 on ${TAILSCALE_IFACE} not found. Check: ufw status"
      fi
    fi

    # Verify public IP is NOT serving the dashboard
    if command -v nc >/dev/null 2>&1 && [[ -n "${DETECTED_TAILSCALE_IP}" ]]; then
      local public_ip
      public_ip="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')"
      if [[ -n "${public_ip}" && "${public_ip}" != "${DETECTED_TAILSCALE_IP}" ]]; then
        if nc -z -w2 "${public_ip}" 8000 2>/dev/null; then
          warn "Port 8000 still appears reachable on public IP ${public_ip}. Check UFW rules."
        else
          log "PASS: Port 8000 not reachable on public IP ${public_ip}"
        fi
      fi
    fi
  fi
}

write_state() {
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: write ${STATE_FILE}"
    return 0
  fi

  install -d -m 0750 "${STATE_DIR}"
  cat > "${STATE_FILE}" <<EOF
script_version=${SCRIPT_VERSION}
applied_at=$(date -Iseconds)
admin_user=${ADMIN_USER}
wan_iface=${WAN_IFACE}
ssh_port=${SSH_PORT}
tailscale_cidr=${TAILSCALE_CIDR}
tunnel_mode=${TUNNEL_MODE}
swap_size=${SWAP_SIZE}
journal_retention=${JOURNAL_RETENTION}
bind_dashboard_to_tailscale=${BIND_DASHBOARD_TO_TAILSCALE}
install_tailscale=${INSTALL_TAILSCALE}
EOF

  # Add detected Tailscale IP if binding was configured
  if is_true "${BIND_DASHBOARD_TO_TAILSCALE}" && [[ -n "${DETECTED_TAILSCALE_IP}" ]]; then
    echo "tailscale_ip=${DETECTED_TAILSCALE_IP}" >> "${STATE_FILE}"
  fi

  chmod 0640 "${STATE_FILE}"
}

generate_report() {
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: write ${REPORT_FILE}"
    return 0
  fi

  local tailscale_iface_present
  local ufw_active
  local ssh_root_disabled
  local ssh_password_disabled
  local journald_persistent
  local auditd_enabled
  local audit_rules_loaded
  local docker_drop_rule
  local docker_drop_rule_v6
  local sysctl_syncookies
  local fail2ban_active
  local banner_present

  tailscale_iface_present="$(bool_cmd ip link show "${TAILSCALE_IFACE}")"
  ufw_active="$(ufw status | grep -q "^Status: active$" && echo "true" || echo "false")"
  local ssh_root_local_only
  ssh_root_disabled="$(sshd -T 2>/dev/null | grep -q "^permitrootlogin no$" && echo "true" || echo "false")"
  ssh_root_local_only="$(sshd -T -C addr=127.0.0.1,user=root,host=localhost,laddr=127.0.0.1 2>/dev/null | grep -qE "^permitrootlogin (prohibit-password|without-password)$" && echo "true" || echo "false")"
  ssh_password_disabled="$(sshd -T 2>/dev/null | grep -q "^passwordauthentication no$" && echo "true" || echo "false")"
  journald_persistent="$(grep -q "^Storage=persistent$" "${JOURNALD_DROPIN_FILE}" && echo "true" || echo "false")"
  auditd_enabled="$(systemctl is-enabled auditd >/dev/null 2>&1 && echo "true" || echo "false")"
  audit_rules_loaded="$(auditctl -l | grep -q "identity" && echo "true" || echo "false")"
  docker_drop_rule="$(iptables -t filter -S DOCKER-USER 2>/dev/null | grep -q "coolify-hardening-wan-drop" && echo "true" || echo "false")"
  docker_drop_rule_v6="$(ip6tables -t filter -S DOCKER-USER 2>/dev/null | grep -q "coolify-hardening-wan-drop6" && echo "true" || echo "false")"
  sysctl_syncookies="$([[ "$(sysctl -n net.ipv4.tcp_syncookies 2>/dev/null)" == "1" ]] && echo "true" || echo "false")"
  local sysctl_bbr
  sysctl_bbr="$([[ "$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null)" == "bbr" ]] && echo "true" || echo "false")"
  local timesync_ntp
  timesync_ntp="$([[ "$(timedatectl show --property=NTP --value 2>/dev/null)" == "yes" ]] && echo "true" || echo "false")"
  local swap_active
  swap_active="$(swapon --show --noheadings 2>/dev/null | grep -q . && echo "true" || echo "false")"
  fail2ban_active="$(systemctl is-active --quiet fail2ban && echo "true" || echo "false")"
  banner_present="$([[ -f /etc/issue.net ]] && echo "true" || echo "false")"

  # Dashboard UFW restriction check (UFW-based; security enforced by UFW not socket binding)
  local coolify_dashboard_bound="false"
  local coolify_dashboard_ip=""
  if is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    coolify_dashboard_ip="${DETECTED_TAILSCALE_IP:-}"
    # Check if UFW rule for port 8000 on tailscale0 is present
    if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "8000.*on ${TAILSCALE_IFACE}"; then
      coolify_dashboard_bound="true"
    fi
  fi

  cat > "${REPORT_FILE}" <<EOF
{
  "generated_at": "$(date -Iseconds)",
  "script_version": "${SCRIPT_VERSION}",
  "os_version": "${OS_VERSION}",
  "admin_user": "${ADMIN_USER}",
  "wan_iface": "${WAN_IFACE}",
  "tailscale_iface": "${TAILSCALE_IFACE}",
  "tailscale_cidr_hint": "${TAILSCALE_CIDR}",
  "ssh_port": ${SSH_PORT},
  "tunnel_mode": $(is_true "${TUNNEL_MODE}" && echo true || echo false),
  "swap_size": "${SWAP_SIZE:-2G}",
  "journal_retention": "${JOURNAL_RETENTION}",
  "auto_reboot_requested": $(is_true "${ENABLE_AUTO_REBOOT}" && echo true || echo false),
  "auto_reboot_time": "${AUTO_REBOOT_TIME}",
  "bind_dashboard_to_tailscale": $(is_true "${BIND_DASHBOARD_TO_TAILSCALE}" && echo true || echo false),
  "install_tailscale": $(is_true "${INSTALL_TAILSCALE}" && echo true || echo false),
  "tailscale_ip": "${DETECTED_TAILSCALE_IP:-}",
  "dry_run": $(is_true "${DRY_RUN}" && echo true || echo false),
  "checks": {
    "tailscale_iface_present": ${tailscale_iface_present},
    "ufw_active": ${ufw_active},
    "ssh_root_login_disabled": ${ssh_root_disabled},
    "ssh_root_local_only_key_auth": ${ssh_root_local_only},
    "ssh_password_auth_disabled": ${ssh_password_disabled},
    "journald_persistent": ${journald_persistent},
    "auditd_enabled": ${auditd_enabled},
    "audit_rules_loaded": ${audit_rules_loaded},
    "docker_user_drop_rule_v4": ${docker_drop_rule},
    "docker_user_drop_rule_v6": ${docker_drop_rule_v6},
    "sysctl_syncookies": ${sysctl_syncookies},
    "sysctl_bbr": ${sysctl_bbr},
    "timesync_ntp": ${timesync_ntp},
    "swap_active": ${swap_active},
    "fail2ban_active": ${fail2ban_active},
    "banner_present": ${banner_present},
    "coolify_dashboard_bound_to_tailscale": ${coolify_dashboard_bound}
  }
}
EOF

  chmod 0600 "${REPORT_FILE}"
}

configure_hardening_validation_timer() {
  if is_true "${DRY_RUN}"; then
    log "DRY-RUN: would install hardening-validate.timer (daily validate_hardening.sh run)."
    return 0
  fi

  # Locate validate_hardening.sh relative to this script, with realpath fallback
  local script_dir validate_src validate_dest
  script_dir="$(cd "${BASH_SOURCE[0]%/*}" 2>/dev/null && pwd)" || script_dir="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
  validate_src="${script_dir}/validate_hardening.sh"
  validate_dest="/usr/local/sbin/validate-hardening"

  if [[ -f "${validate_src}" ]]; then
    install -m 0750 -o root -g root "${validate_src}" "${validate_dest}"
    log "Installed ${validate_src} → ${validate_dest}"
  else
    warn "validate_hardening.sh not found at ${validate_src}; skipping timer install."
    return 0
  fi

  cat > /etc/systemd/system/hardening-validate.service <<'SVCEOF'
[Unit]
Description=Run hardening validation checks
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/validate-hardening
SVCEOF

  cat > /etc/systemd/system/hardening-validate.timer <<'TIMEREOF'
[Unit]
Description=Daily hardening validation

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
TIMEREOF

  run systemctl daemon-reload
  run systemctl enable --now hardening-validate.timer
  log "hardening-validate.timer enabled (runs validate_hardening.sh daily)."
}

main() {
  parse_args "$@"
  require_root
  setup_logging

  log "Starting ${SCRIPT_NAME} v${SCRIPT_VERSION}"
  warn_on_state_version_mismatch
  validate_inputs
  detect_os
  check_disk_space

  log "Verifying NTP time synchronization."
  ensure_timesync

  detect_wan_iface
  ssh_session_safety_gate
  ensure_packages

  # Install Tailscale if requested (before verify_tailscale_iface)
  if is_true "${INSTALL_TAILSCALE}"; then
    log "Installing Tailscale."
    install_tailscale
  fi

  require_commands
  verify_tailscale_iface
  detect_docker

  log "Configuring swap."
  configure_swap

  log "Disabling unused network services."
  disable_unused_services

  log "Applying login banner."
  configure_banner

  log "Applying account and SSH hardening."
  ensure_admin_access
  configure_ssh

  log "Applying auditd baseline."
  configure_auditd

  log "Applying sysctl kernel hardening."
  configure_sysctl

  log "Applying UFW baseline."
  configure_ufw

  log "Applying Docker daemon log rotation."
  configure_docker_daemon

  log "Applying DOCKER-USER hardening assets."
  configure_docker_user

  if is_true "${DOCKER_DAEMON_NEEDS_RESTART}"; then
    log "Restarting Docker (deferred from daemon.json update, DOCKER-USER rules already applied)."
    run systemctl restart docker
    if [[ "${DOCKER_PRESENT}" == "true" ]]; then
      run systemctl start docker-user-hardening.service
    fi
  fi

  log "Applying fail2ban."
  configure_fail2ban

  log "Applying journald persistence."
  configure_journald

  log "Applying unattended-upgrades policy."
  configure_unattended_upgrades
  log "Installing hardening validation timer."
  configure_hardening_validation_timer

  # Configure Coolify split-horizon binding if requested
  if is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    log "Configuring Coolify split-horizon binding."
    configure_coolify_binding
    log "Installing Coolify binding watchdog."
    configure_coolify_binding_watchdog
  fi

  write_state

  log "Running post-apply checks."
  run_post_checks

  # Ensure DETECTED_TAILSCALE_IP is populated for generate_report() regardless of
  # --bind-dashboard-to-tailscale (get_tailscale_ip only runs inside configure_coolify_binding
  # which is skipped when binding is not requested). Do this BEFORE generate_report.
  if [[ -z "${DETECTED_TAILSCALE_IP}" ]]; then
    get_tailscale_ip >/dev/null 2>&1 || true
  fi

  generate_report
  log "Completed hardening bootstrap successfully."

  # Print Tailscale IP on stdout for orchestrators (deploy.sh) to capture.
  # Post-hardening, UFW blocks all SSH on the public IP (tailscale0 only), so
  # the orchestrator cannot run 'tailscale ip -4' via a new root SSH session.
  local _ts_ip_final
  _ts_ip_final="$(tailscale ip -4 2>/dev/null)" || true
  [[ -n "${_ts_ip_final}" ]] && printf 'HARDEN_RESULT_TAILSCALE_IP=%s\n' "${_ts_ip_final}"
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  main "$@"
fi
