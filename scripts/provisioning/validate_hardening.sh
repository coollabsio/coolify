#!/usr/bin/env bash
set -Eeuo pipefail

# validate_hardening.sh — Standalone health-check companion for bootstrap_hardening.sh
# Re-runnable: prints PASS/FAIL per check, exits 0 if all pass, 1 if any fail.
# Usage: sudo ./validate_hardening.sh [--json|--health-check]

STATE_FILE="/var/lib/bootstrap-hardening/state"
JOURNALD_DROPIN="/etc/systemd/journald.conf.d/60-persistent.conf"
JSON_MODE="false"
HEALTH_CHECK_MODE="false"
IS_CONTAINER="false"

for arg in "$@"; do
  case "${arg}" in
    --json) JSON_MODE="true" ;;
    --health-check) HEALTH_CHECK_MODE="true" ;;
  esac
done

if [[ "${JSON_MODE}" == "true" && "${HEALTH_CHECK_MODE}" == "true" ]]; then
  printf 'Error: --json and --health-check are mutually exclusive.\n' >&2
  exit 1
fi

if [[ -f /.dockerenv || "${container:-}" == "docker" ]]; then
  IS_CONTAINER="true"
fi

PASS_COUNT=0
FAIL_COUNT=0
INFO_COUNT=0
declare -a RESULTS=()

record() {
  local status="$1"
  local name="$2"
  local detail="${3:-}"

  case "${status}" in
    PASS) ((++PASS_COUNT)) ;;
    FAIL) ((++FAIL_COUNT)) ;;
    INFO) ((++INFO_COUNT)) ;;
  esac

  if [[ "${JSON_MODE}" == "true" ]]; then
    # Escape special characters for valid JSON output
    local escaped_name escaped_detail
    escaped_name="$(printf '%s' "${name}" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' ')"
    escaped_detail="$(printf '%s' "${detail}" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr '\n' ' ')"
    RESULTS+=("{\"check\":\"${escaped_name}\",\"status\":\"${status}\",\"detail\":\"${escaped_detail}\"}")
  elif [[ "${HEALTH_CHECK_MODE}" != "true" ]]; then
    printf '%-6s %-45s %s\n' "[${status}]" "${name}" "${detail}"
  else
    :
  fi
}

check() {
  local name="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    record "PASS" "${name}"
  else
    record "FAIL" "${name}" "$*"
  fi
}

# Load state file for context (non-fatal if missing)
ADMIN_USER=""
SSH_PORT="22"
TUNNEL_MODE="false"
WAN_IFACE=""
TAILSCALE_IFACE="tailscale0"
BIND_DASHBOARD_TO_TAILSCALE="false"
TAILSCALE_IP=""
TAILSCALE_CIDR="100.64.0.0/10"
COOLIFY_ENV_FILE="/data/coolify/source/.env"

if [[ -f "${STATE_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${STATE_FILE}"
  ADMIN_USER="${admin_user:-}"
  SSH_PORT="${ssh_port:-22}"
  TUNNEL_MODE="${tunnel_mode:-false}"
  WAN_IFACE="${wan_iface:-}"
  swap_size="${swap_size:-2G}"
  BIND_DASHBOARD_TO_TAILSCALE="${bind_dashboard_to_tailscale:-false}"
  TAILSCALE_IP="${tailscale_ip:-}"
  TAILSCALE_CIDR="${tailscale_cidr:-100.64.0.0/10}"
fi

is_true() {
  case "${1,,}" in
    1|true|yes|y|on) return 0 ;;
    *) return 1 ;;
  esac
}

# ── SSH effective config ──

ssh_check() {
  local effective
  effective="$(sshd -T 2>/dev/null)" || { record "FAIL" "ssh: sshd -T" "cannot query"; return; }

  local field val expected
  declare -A ssh_expects=(
    [permitrootlogin]="no"
    [passwordauthentication]="no"
    [pubkeyauthentication]="yes"
    [permitemptypasswords]="no"
    [compression]="no"
  )

  for field in "${!ssh_expects[@]}"; do
    expected="${ssh_expects[${field}]}"
    val="$(grep -m1 "^${field} " <<< "${effective}" | awk '{print $2}')"
    if [[ "${val}" == "${expected}" ]]; then
      record "PASS" "ssh: ${field}=${val}"
    else
      record "FAIL" "ssh: ${field}" "expected ${expected}, got ${val:-<empty>}"
    fi
  done

  if grep -q "chacha20-poly1305@openssh.com" <<< "${effective}"; then
    record "PASS" "ssh: cipher restrictions present"
  else
    record "FAIL" "ssh: cipher restrictions" "chacha20-poly1305 not in ciphers"
  fi

  if [[ -n "${ADMIN_USER}" ]]; then
    if grep -qE "^allowusers .*\\b${ADMIN_USER}\\b" <<< "${effective}"; then
      record "PASS" "ssh: AllowUsers includes ${ADMIN_USER}"
    else
      record "FAIL" "ssh: AllowUsers" "${ADMIN_USER} not listed"
    fi
  fi

  # Verify Match Address block: root key-only login from localhost/Docker bridge (10.0.0.0/8)
  local match_local
  match_local="$(sshd -T -C addr=127.0.0.1,user=root,host=localhost,laddr=127.0.0.1 2>/dev/null)" || true
  if [[ -n "${match_local}" ]]; then
    local match_root_val
    match_root_val="$(grep -m1 "^permitrootlogin " <<< "${match_local}" | awk '{print $2}')"
    if grep -qE "^permitrootlogin (prohibit-password|without-password)$" <<< "${match_local}"; then
      record "PASS" "ssh: Match localhost root=prohibit-password"
    else
      record "FAIL" "ssh: Match localhost root" "expected prohibit-password/without-password, got ${match_root_val:-<empty>}"
    fi

    if grep -qE "^allowusers .*\\broot\\b" <<< "${match_local}"; then
      record "PASS" "ssh: Match localhost AllowUsers includes root"
    else
      record "FAIL" "ssh: Match localhost AllowUsers" "root not listed"
    fi
  fi

  # Verify Docker bridge address (10.0.0.0/8) also gets the Match block
  local match_docker
  match_docker="$(sshd -T -C addr=10.0.1.5,user=root,host=10.0.1.5,laddr=10.0.1.1 2>/dev/null)" || true
  if [[ -n "${match_docker}" ]]; then
    if grep -qE "^permitrootlogin (prohibit-password|without-password)$" <<< "${match_docker}"; then
      record "PASS" "ssh: Match Docker bridge root=prohibit-password"
    else
      local docker_root_val
      docker_root_val="$(grep -m1 "^permitrootlogin " <<< "${match_docker}" | awk '{print $2}')"
      record "FAIL" "ssh: Match Docker bridge root" \
        "expected prohibit-password, got ${docker_root_val:-<empty>} — Coolify SSH will be denied"
    fi
  fi

  # Verify external addresses still deny root
  local match_external
  match_external="$(sshd -T -C addr=203.0.113.1,user=root,host=example.com,laddr=0.0.0.0 2>/dev/null)" || true
  if [[ -n "${match_external}" ]]; then
    local ext_root_val
    ext_root_val="$(grep -m1 "^permitrootlogin " <<< "${match_external}" | awk '{print $2}')"
    if [[ "${ext_root_val}" == "no" ]]; then
      record "PASS" "ssh: external root login denied"
    else
      record "FAIL" "ssh: external root login" "expected no, got ${ext_root_val:-<empty>}"
    fi
  fi
}

# ── UFW ──

ufw_check() {
  local ufw_out
  ufw_out="$(ufw status verbose 2>/dev/null)" || { record "FAIL" "ufw: status query" "cannot run ufw"; return; }
  ufw_has_port_on_iface() {
    local port="$1" iface="$2"
    grep -qE "${port}/tcp.*(on[[:space:]]+${iface}.*ALLOW IN|ALLOW IN.*on[[:space:]]+${iface})" <<< "${ufw_out}"
  }
  ufw_has_port_anywhere_unscoped() {
    local port="$1"
    grep -qE "${port}/tcp[[:space:]]+ALLOW IN[[:space:]]+Anywhere([[:space:]]+\\(v6\\))?$" <<< "${ufw_out}"
  }

  if grep -q "^Status: active$" <<< "${ufw_out}"; then
    record "PASS" "ufw: active"
  else
    record "FAIL" "ufw: active" "UFW is not active"
    return
  fi

  if ufw_has_port_on_iface "${SSH_PORT}" "${TAILSCALE_IFACE}"; then
    record "PASS" "ufw: SSH on ${TAILSCALE_IFACE}"
  else
    record "FAIL" "ufw: SSH on ${TAILSCALE_IFACE}" "rule missing"
  fi

  # Coolify SSHes from its Docker container (10.0.0.0/8) to the host.
  if grep -qE "${SSH_PORT}.*ALLOW.*10\.0\.0\.0/8" <<< "${ufw_out}"; then
    record "PASS" "ufw: SSH from Docker bridge (10.0.0.0/8)"
  else
    record "FAIL" "ufw: SSH from Docker bridge" "10.0.0.0/8 → port ${SSH_PORT} rule missing — Coolify cannot reach host"
  fi

  # Coolify dashboard (8000), Soketi (6001), terminal (6002) on Tailscale only.
  for port_label in "8000:dashboard" "6001:soketi" "6002:terminal"; do
    local port="${port_label%%:*}" label="${port_label##*:}"
    if ufw_has_port_on_iface "${port}" "${TAILSCALE_IFACE}"; then
      record "PASS" "ufw: Coolify ${label} (${port}) on ${TAILSCALE_IFACE}"
    else
      record "FAIL" "ufw: Coolify ${label} (${port})" "port ${port} not allowed on ${TAILSCALE_IFACE}"
    fi
  done

  if [[ -n "${WAN_IFACE}" ]]; then
    # SSH must not be on WAN
    if ufw_has_port_on_iface "${SSH_PORT}" "${WAN_IFACE}" \
      || ufw_has_port_anywhere_unscoped "${SSH_PORT}"; then
      record "FAIL" "ufw: SSH NOT on WAN" "SSH allowed on ${WAN_IFACE}"
    else
      record "PASS" "ufw: SSH NOT on WAN"
    fi

    # Coolify ports must not be on WAN (must only be on tailscale0)
    for port_label in "8000:dashboard" "6001:soketi" "6002:terminal"; do
      local port="${port_label%%:*}" label="${port_label##*:}"
      if ufw_has_port_on_iface "${port}" "${WAN_IFACE}" \
         || ufw_has_port_anywhere_unscoped "${port}"; then
        record "FAIL" "ufw: ${label} (${port}) NOT on WAN" \
          "port ${port} allowed on WAN — must be tailscale0-only"
      else
        record "PASS" "ufw: ${label} (${port}) NOT on WAN"
      fi
    done

    if is_true "${TUNNEL_MODE}"; then
      if ufw_has_port_on_iface "80" "${WAN_IFACE}" \
        || ufw_has_port_anywhere_unscoped "80"; then
        record "FAIL" "ufw: tunnel-mode no port 80" "WAN 80 rule exists"
      else
        record "PASS" "ufw: tunnel-mode no port 80"
      fi
      if ufw_has_port_on_iface "443" "${WAN_IFACE}" \
        || ufw_has_port_anywhere_unscoped "443"; then
        record "FAIL" "ufw: tunnel-mode no port 443" "WAN 443 rule exists"
      else
        record "PASS" "ufw: tunnel-mode no port 443"
      fi
    fi
  fi
}

# ── DOCKER-USER iptables (IPv4 + IPv6) ──

docker_user_check() {
  if ! command -v iptables >/dev/null 2>&1; then
    record "FAIL" "docker-user: iptables" "iptables not found"
    return
  fi

  # Check if Docker is installed first
  if ! command -v docker >/dev/null 2>&1; then
    record "INFO" "docker-user: Docker" "Docker not installed; skipping DOCKER-USER checks"
    return
  fi

  local rules
  rules="$(iptables -t filter -S DOCKER-USER 2>/dev/null)" || { record "FAIL" "docker-user: IPv4" "DOCKER-USER chain absent (Docker may need restart)"; return; }

  if grep -q "coolify-hardening-wan-drop" <<< "${rules}"; then
    record "PASS" "docker-user: IPv4 wan-drop"
  else
    record "FAIL" "docker-user: IPv4 wan-drop" "rule missing"
  fi

  if grep -q "coolify-hardening-bridge-docker0" <<< "${rules}"; then
    record "PASS" "docker-user: IPv4 bridge-docker0"
  else
    record "FAIL" "docker-user: IPv4 bridge-docker0" "rule missing"
  fi

  if is_true "${TUNNEL_MODE}" && grep -q "coolify-hardening-wan-web" <<< "${rules}"; then
    record "FAIL" "docker-user: tunnel-mode no wan-web" "wan-web ACCEPT present"
  elif is_true "${TUNNEL_MODE}"; then
    record "PASS" "docker-user: tunnel-mode no wan-web"
  fi

  if command -v ip6tables >/dev/null 2>&1; then
    local rules6
    rules6="$(ip6tables -t filter -S DOCKER-USER 2>/dev/null)" || { record "INFO" "docker-user: IPv6" "DOCKER-USER chain absent"; return; }

    if grep -q "coolify-hardening-wan-drop6" <<< "${rules6}"; then
      record "PASS" "docker-user: IPv6 wan-drop6"
    else
      record "FAIL" "docker-user: IPv6 wan-drop6" "rule missing"
    fi
  else
    record "INFO" "docker-user: IPv6" "ip6tables not available"
  fi
}

# ── docker-user-hardening service lifecycle ──

docker_user_lifecycle_check() {
  local unit_file="/etc/systemd/system/docker-user-hardening.service"
  if [[ ! -f "${unit_file}" ]]; then
    if command -v docker >/dev/null 2>&1; then
      record "FAIL" "docker-user: unit file" "not found at ${unit_file}"
    else
      record "INFO" "docker-user: unit file" "Docker not installed; skipped"
    fi
    return
  fi

  if grep -q "PartOf=docker.service" "${unit_file}"; then
    record "PASS" "docker-user: PartOf=docker.service"
  else
    record "FAIL" "docker-user: PartOf=docker.service" "missing — rules lost on Docker daemon restart"
  fi

  if grep -q "WantedBy=docker.service" "${unit_file}"; then
    record "PASS" "docker-user: WantedBy=docker.service"
  else
    record "FAIL" "docker-user: WantedBy=docker.service" "missing — rules may not re-apply after Docker start"
  fi

  # Functional: service must have run at least once since boot (rules are only in iptables if it did).
  local active_state
  active_state="$(systemctl show docker-user-hardening.service --property=ActiveState --value 2>/dev/null || echo "unknown")"
  if [[ "${active_state}" == "active" || "${active_state}" == "activating" ]]; then
    record "PASS" "docker-user: service has run (${active_state})"
  else
    # For a oneshot service, "inactive" is normal after a successful run.
    local result
    result="$(systemctl show docker-user-hardening.service --property=Result --value 2>/dev/null || echo "unknown")"
    if [[ "${result}" == "success" ]]; then
      record "PASS" "docker-user: service completed successfully"
    else
      record "FAIL" "docker-user: service result" "result=${result} — rules may not have been applied"
    fi
  fi
}

# ── Sysctl ──

sysctl_check() {
  local key expected val
  declare -A sysctl_expects=(
    [net.ipv4.tcp_syncookies]="1"
    [net.ipv4.ip_forward]="1"
    [net.ipv4.conf.all.rp_filter]="2"
    [net.ipv4.tcp_max_syn_backlog]="2048"
    [net.ipv4.tcp_synack_retries]="2"
    [fs.protected_hardlinks]="1"
    [fs.protected_symlinks]="1"
    [fs.suid_dumpable]="0"
    [kernel.unprivileged_bpf_disabled]="1"
    [kernel.kexec_load_disabled]="1"
    [kernel.sysrq]="4"
    [kernel.randomize_va_space]="2"
    [kernel.dmesg_restrict]="1"
    [kernel.perf_event_paranoid]="3"
    [kernel.yama.ptrace_scope]="1"
    [kernel.kptr_restrict]="2"
    [vm.swappiness]="10"
  )

  for key in "${!sysctl_expects[@]}"; do
    expected="${sysctl_expects[${key}]}"
    val="$(sysctl -n "${key}" 2>/dev/null || echo "?")"
    if [[ "${val}" == "${expected}" ]]; then
      record "PASS" "sysctl: ${key}=${val}"
    elif [[ "${val}" == "?" && "${IS_CONTAINER}" == "true" ]]; then
      record "INFO" "sysctl: ${key}" "unavailable in container namespace"
    else
      record "FAIL" "sysctl: ${key}" "expected ${expected}, got ${val}"
    fi
  done

  # BBR congestion control (informational — depends on kernel module availability)
  local bbr_val
  bbr_val="$(sysctl -n net.ipv4.tcp_congestion_control 2>/dev/null || echo "?")"
  if [[ "${bbr_val}" == "bbr" ]]; then
    record "PASS" "sysctl: tcp_congestion_control=bbr"
  elif [[ "${bbr_val}" == "?" && "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "sysctl: tcp_congestion_control" "unavailable in container namespace"
  else
    record "INFO" "sysctl: tcp_congestion_control=${bbr_val}" "BBR not active (kernel module may be unavailable)"
  fi

  local qdisc_val
  qdisc_val="$(sysctl -n net.core.default_qdisc 2>/dev/null || echo "?")"
  if [[ "${qdisc_val}" == "fq" ]]; then
    record "PASS" "sysctl: default_qdisc=fq"
  elif [[ "${qdisc_val}" == "?" && "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "sysctl: default_qdisc" "unavailable in container namespace"
  else
    record "INFO" "sysctl: default_qdisc=${qdisc_val}" "fq not active (BBR may be unavailable)"
  fi
}

# ── fail2ban ──

fail2ban_check() {
  if systemctl is-active --quiet fail2ban 2>/dev/null; then
    record "PASS" "fail2ban: active"
  else
    record "FAIL" "fail2ban: active" "service not running"
    return
  fi

  if fail2ban-client status sshd >/dev/null 2>&1; then
    record "PASS" "fail2ban: sshd jail enabled"
  else
    record "FAIL" "fail2ban: sshd jail" "jail not active"
  fi

  local _jail_file="/etc/fail2ban/jail.d/coolify-hardening.local"
  if [[ -f "${_jail_file}" ]] && grep -Fq "${TAILSCALE_CIDR}" "${_jail_file}"; then
    record "PASS" "fail2ban: ignoreip includes Tailscale CIDR"
  elif [[ ! -f "${_jail_file}" ]]; then
    record "FAIL" "fail2ban: ignoreip" "jail file missing"
  else
    record "FAIL" "fail2ban: ignoreip" "${TAILSCALE_CIDR} not in ignoreip"
  fi

  # Functional check: verify fail2ban's ban backend is operational.
  # When banaction=ufw, fail2ban delegates to UFW instead of creating iptables chains directly.
  # When banaction=iptables-multiport (default), it creates f2b-* chains.
  local banaction
  banaction="$(grep -m1 '^banaction' /etc/fail2ban/jail.d/coolify-hardening.local 2>/dev/null \
    | awk '{print $3}' || true)"
  banaction="${banaction:-iptables-multiport}"

  if [[ "${banaction}" == "ufw" ]]; then
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "^Status: active"; then
      record "PASS" "fail2ban: banaction=ufw and UFW active"
    else
      record "FAIL" "fail2ban: banaction=ufw" "UFW not active — fail2ban bans will silently fail"
    fi
  else
    if iptables -L f2b-sshd >/dev/null 2>&1; then
      record "PASS" "fail2ban: f2b-sshd iptables chain present"
    else
      record "FAIL" "fail2ban: f2b-sshd iptables chain" "chain missing — fail2ban may not have hooked into iptables"
    fi
  fi
}

# ── auditd ──

auditd_check() {
  if systemctl is-active --quiet auditd 2>/dev/null; then
    record "PASS" "auditd: active"
  elif [[ "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "auditd: active" "not active in container test environment"
  else
    record "FAIL" "auditd: active" "service not running"
    return
  fi

  local rules
  rules="$(auditctl -l 2>/dev/null)" || { record "FAIL" "auditd: rules" "cannot list"; return; }

  if grep -q "identity" <<< "${rules}"; then
    record "PASS" "auditd: identity rules loaded"
  else
    record "FAIL" "auditd: identity rules" "not loaded"
  fi

  if grep -q "sudoers-change" <<< "${rules}"; then
    record "PASS" "auditd: sudoers rules loaded"
  else
    record "FAIL" "auditd: sudoers rules" "not loaded"
  fi
}

# ── journald ──

journald_check() {
  if [[ -f "${JOURNALD_DROPIN}" ]] && grep -q "^Storage=persistent$" "${JOURNALD_DROPIN}"; then
    record "PASS" "journald: persistent storage"
  else
    record "FAIL" "journald: persistent storage" "drop-in missing or not persistent"
  fi

  local usage
  usage="$(journalctl --disk-usage 2>/dev/null | head -1)" || true
  if [[ -n "${usage}" ]]; then
    record "INFO" "journald: disk usage" "${usage}"
  fi
}

# ── NTP / Timesync ──

timesync_check() {
  local ntp_val
  ntp_val="$(timedatectl show --property=NTP --value 2>/dev/null || echo "?")"
  if [[ "${ntp_val}" == "yes" ]]; then
    record "PASS" "timesync: NTP active"
  elif [[ "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "timesync: NTP" "unavailable in container"
  else
    record "FAIL" "timesync: NTP" "not active"
  fi

  local synced_val
  synced_val="$(timedatectl show --property=NTPSynchronized --value 2>/dev/null || echo "?")"
  if [[ "${synced_val}" == "yes" ]]; then
    record "PASS" "timesync: NTPSynchronized"
  elif [[ "${IS_CONTAINER}" == "true" || "${ntp_val}" != "yes" ]]; then
    record "INFO" "timesync: NTPSynchronized" "skipped (NTP not active or container)"
  else
    record "FAIL" "timesync: NTPSynchronized" "not yet synchronized"
  fi
}

# ── Swap ──

swap_check() {
  local swap_size="${swap_size:-2G}"
  if [[ "${swap_size}" == "0" ]]; then
    record "INFO" "swap: disabled" "swap creation was skipped (--swap-size 0)"
    return
  fi

  if swapon --show --noheadings 2>/dev/null | grep -q .; then
    local swap_total swap_output
    # swapon --show output format: NAME TYPE SIZE USED PRIO
    # SIZE column position varies; use --bytes and sum the SIZE column (3rd field)
    swap_output="$(swapon --show --noheadings --bytes 2>/dev/null)" || true
    if [[ -n "${swap_output}" ]]; then
      swap_total="$(awk '{sum+=$3} END {printf "%.0fM", sum/1048576}' <<< "${swap_output}")"
      record "PASS" "swap: active (${swap_total})"
    else
      record "PASS" "swap: active"
    fi
  elif [[ "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "swap: status" "unavailable in container"
  else
    record "FAIL" "swap: active" "no swap detected"
  fi

  if [[ -f /swapfile ]]; then
    local perms
    perms="$(stat -c '%a' /swapfile 2>/dev/null || echo "?")"
    if [[ "${perms}" == "600" ]]; then
      record "PASS" "swap: /swapfile permissions 0600"
    else
      record "FAIL" "swap: /swapfile permissions" "expected 600, got ${perms}"
    fi
  fi

  local fstab_count
  # Match any /swapfile fstab entry regardless of options format.
  # Old Ubuntu: "/swapfile none swap sw 0 0"
  # Modern Ubuntu: "/swapfile swap swap defaults 0 0"
  fstab_count="$(grep -cE '^/swapfile[[:space:]]' /etc/fstab 2>/dev/null || true)"
  fstab_count="${fstab_count:-0}"
  if [[ "${fstab_count}" == "1" ]]; then
    record "PASS" "swap: single fstab entry"
  elif [[ "${fstab_count}" == "0" && "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "swap: fstab" "not applicable in container"
  elif (( fstab_count > 1 )); then
    record "FAIL" "swap: fstab" "duplicate entries (${fstab_count})"
  else
    record "FAIL" "swap: fstab" "entry not found in /etc/fstab — swap will not persist on reboot"
  fi
}

# ── Banner ──

banner_check() {
  if [[ -f /etc/issue.net ]] && grep -q "AUTHORIZED" /etc/issue.net; then
    record "PASS" "banner: /etc/issue.net present"
  else
    record "FAIL" "banner: /etc/issue.net" "missing or no AUTHORIZED text"
  fi
}

# ── Admin sudo access ──

admin_sudo_check() {
  # Skip if no admin user configured
  if [[ -z "${ADMIN_USER}" ]]; then
    record "INFO" "admin: sudo" "no admin user in state file"
    return 0
  fi

  # Check if admin user exists
  if ! id "${ADMIN_USER}" >/dev/null 2>&1; then
    record "FAIL" "admin: user" "${ADMIN_USER} does not exist"
    return 0
  fi

  # Check if admin user is in sudo group
  if id -nG "${ADMIN_USER}" | tr ' ' '\n' | grep -qx "sudo"; then
    record "PASS" "admin: in sudo group"
  else
    record "FAIL" "admin: sudo group" "${ADMIN_USER} not in sudo group"
    return 0
  fi

  # Check if passwordless sudo is configured.
  # Passwordless sudo is required: ssh_admin_sudo in the orchestrator runs non-interactively
  # and will hang waiting for a password prompt if NOPASSWD is absent.
  local sudoers_file="/etc/sudoers.d/${ADMIN_USER}"
  if [[ -f "${sudoers_file}" ]]; then
    if grep -q "NOPASSWD" "${sudoers_file}" 2>/dev/null; then
      record "PASS" "admin: passwordless sudo"
    else
      record "FAIL" "admin: sudo" "sudoers file exists but NOPASSWD not set — ssh_admin_sudo will hang"
    fi
  else
    # Check if sudo -l shows NOPASSWD for this user
    if sudo -l -U "${ADMIN_USER}" 2>/dev/null | grep -q "NOPASSWD"; then
      record "PASS" "admin: passwordless sudo (via other config)"
    else
      record "FAIL" "admin: sudo" "NOPASSWD not configured — ssh_admin_sudo will hang"
    fi
  fi

  # Check admin authorized_keys: file must exist, be non-empty, and each key must be
  # on its own line. The concatenation bug (missing trailing newline on a prior key)
  # would still allow sudo to work while silently breaking SSH login.
  local home_dir auth_file
  home_dir="$(getent passwd "${ADMIN_USER}" | cut -d: -f6 2>/dev/null)" || true
  auth_file="${home_dir}/.ssh/authorized_keys"
  if [[ ! -f "${auth_file}" ]]; then
    record "FAIL" "admin: authorized_keys exists" "${auth_file} not found"
  elif [[ ! -s "${auth_file}" ]]; then
    record "FAIL" "admin: authorized_keys non-empty" "${auth_file} is empty"
  else
    # Allow valid key lines with optional OpenSSH options prefix:
    # from="...",command="...",no-agent-forwarding,... ssh-ed25519 AAAA...
    # Flag only lines that do not contain a recognized key type token.
    local bad_lines
    bad_lines="$(
      awk '
        /^[[:space:]]*($|#)/ { next }
        /(^|[[:space:]])(ssh-[^[:space:]]+|ecdsa-sha2-[^[:space:]]+|sk-[^[:space:]]+)[[:space:]]+/ { next }
        { bad++ }
        END { print bad + 0 }
      ' "${auth_file}" 2>/dev/null
    )" || bad_lines="0"
    if [[ "${bad_lines}" -eq 0 ]]; then
      record "PASS" "admin: authorized_keys format"
    else
      record "FAIL" "admin: authorized_keys format" \
        "${bad_lines} line(s) do not start with a valid key type (possible concatenation bug)"
    fi
  fi
}

# ── Docker daemon.json ──
# Hardening owns: log-driver, log-opts, live-restore. Coolify may add: default-address-pools.
# Using json-file driver to match Coolify's expectation for compatibility.

docker_daemon_check() {
  local daemon_json="/etc/docker/daemon.json"
  if [[ ! -f "${daemon_json}" ]]; then
    if command -v docker >/dev/null 2>&1; then
      record "FAIL" "docker-daemon: daemon.json" "file missing (no log rotation)"
    else
      record "INFO" "docker-daemon: daemon.json" "Docker not installed; skipped"
    fi
    return
  fi

  # Check log-driver is json-file (matches Coolify's expectation)
  local log_driver
  log_driver="$(jq -r '.["log-driver"] // ""' "${daemon_json}" 2>/dev/null || true)"
  if [[ "${log_driver}" == "json-file" ]]; then
    record "PASS" "docker-daemon: log-driver is json-file"
  elif [[ "${log_driver}" == "" ]]; then
    record "FAIL" "docker-daemon: log-driver" "not set in daemon.json"
  else
    record "FAIL" "docker-daemon: log-driver" "expected 'json-file', got '${log_driver}'"
  fi

  # Check log-opts have rotation configured
  if jq -e '.["log-opts"]["max-size"]' "${daemon_json}" >/dev/null 2>&1; then
    record "PASS" "docker-daemon: log-opts.max-size configured"
  else
    record "FAIL" "docker-daemon: log-opts.max-size" "not set in daemon.json"
  fi

  if grep -q '"live-restore"' "${daemon_json}"; then
    record "PASS" "docker-daemon: live-restore configured"
  else
    record "FAIL" "docker-daemon: live-restore" "not set in daemon.json"
  fi

  # Verify the RUNNING daemon matches the config file — daemon.json changes only take
  # effect after a restart, so file and live state can diverge silently.
  if docker info >/dev/null 2>&1; then
    local live_driver
    live_driver="$(docker info --format '{{.LoggingDriver}}' 2>/dev/null || true)"
    if [[ "${live_driver}" == "json-file" ]]; then
      record "PASS" "docker-daemon: live log-driver is json-file"
    elif [[ -n "${live_driver}" ]]; then
      record "FAIL" "docker-daemon: live log-driver" \
        "daemon reports '${live_driver}' — restart Docker to apply daemon.json"
    fi
  else
    record "INFO" "docker-daemon: live config" "docker daemon not responding; skipping live check"
  fi
}

# ── AppArmor ──

apparmor_check() {
  if command -v aa-status >/dev/null 2>&1; then
    if aa-status --enabled 2>/dev/null; then
      record "PASS" "apparmor: enabled"
    elif [[ "${IS_CONTAINER}" == "true" ]]; then
      record "INFO" "apparmor: status" "cannot verify in container"
    else
      record "FAIL" "apparmor: enabled" "AppArmor not enabled"
    fi
  elif [[ "${IS_CONTAINER}" == "true" ]]; then
    record "INFO" "apparmor: status" "cannot check in container"
  else
    record "FAIL" "apparmor: aa-status" "command not found"
  fi
}

# ── Disabled services ──

disabled_services_check() {
  local svc
  for svc in rpcbind avahi-daemon cups; do
    local state="not-found"
    state="$(systemctl is-enabled "${svc}.service" 2>/dev/null || true)"
    state="${state%%$'\n'*}"
    [[ -n "${state}" ]] || state="not-found"

    if [[ "${state}" == masked* || "${state}" == "not-found" ]]; then
      record "PASS" "disabled: ${svc} (${state})"
    else
      record "FAIL" "disabled: ${svc}" "state is ${state}, expected masked"
    fi
  done
}

# ── Tailscale interface ──

tailscale_check() {
  if ip link show "${TAILSCALE_IFACE}" >/dev/null 2>&1; then
    record "PASS" "tailscale: ${TAILSCALE_IFACE} present"
  else
    record "FAIL" "tailscale: ${TAILSCALE_IFACE}" "interface not found"
    return
  fi

  # Check actual connection state via tailscale CLI (not just interface presence).
  # Interface can exist while the daemon is in a broken/logged-out state.
  if command -v tailscale >/dev/null 2>&1; then
    local ts_state
    ts_state="$(tailscale status --json 2>/dev/null \
      | python3 -c "import sys,json; print(json.load(sys.stdin).get('BackendState','unknown'))" \
      2>/dev/null || echo "unknown")"
    if [[ "${ts_state}" == "Running" ]]; then
      record "PASS" "tailscale: BackendState=Running"
    else
      record "FAIL" "tailscale: BackendState" "expected Running, got ${ts_state}"
    fi

    # Check that a Tailscale IPv4 (100.x) has actually been assigned
    local ts_ip
    ts_ip="$(tailscale ip -4 2>/dev/null || true)"
    if [[ -n "${ts_ip}" ]]; then
      record "PASS" "tailscale: IPv4 assigned (${ts_ip})"
    else
      record "FAIL" "tailscale: IPv4 address" "no Tailscale IPv4 — check auth key and login state"
    fi
  else
    record "INFO" "tailscale: CLI" "tailscale binary not found; skipping state/IP checks"
  fi
}

# ── Coolify split-horizon binding ──

coolify_binding_check() {
  # Skip if binding restriction was not configured
  if ! is_true "${BIND_DASHBOARD_TO_TAILSCALE}"; then
    record "INFO" "coolify: dashboard UFW restriction" "not configured (use --bind-dashboard-to-tailscale)"
    return 0
  fi

  # Dashboard Tailscale restriction is enforced via UFW rules on tailscale0, not by
  # socket binding (APP_PORT=IP:port breaks Coolify's expose: directive). Check UFW rules.

  if ! command -v ufw >/dev/null 2>&1; then
    record "FAIL" "coolify: ufw" "ufw command not found"
    return 0
  fi

  local ufw_out
  ufw_out="$(ufw status 2>/dev/null)" || true

  # Check UFW rule for port 8000 on tailscale0
  if echo "${ufw_out}" | grep -q "8000.*on ${TAILSCALE_IFACE}"; then
    record "PASS" "coolify: UFW rule port 8000 on ${TAILSCALE_IFACE}"
  else
    record "FAIL" "coolify: UFW rule port 8000" "rule for port 8000 on ${TAILSCALE_IFACE} missing"
  fi

  # Check UFW rule for port 6001 on tailscale0
  if echo "${ufw_out}" | grep -q "6001.*on ${TAILSCALE_IFACE}"; then
    record "PASS" "coolify: UFW rule port 6001 on ${TAILSCALE_IFACE}"
  else
    record "INFO" "coolify: UFW rule port 6001" "rule for port 6001 on ${TAILSCALE_IFACE} missing (Soketi may not be in use)"
  fi

  # Check UFW rule for port 6002 on tailscale0
  if echo "${ufw_out}" | grep -q "6002.*on ${TAILSCALE_IFACE}"; then
    record "PASS" "coolify: UFW rule port 6002 on ${TAILSCALE_IFACE}"
  else
    record "INFO" "coolify: UFW rule port 6002" "rule for port 6002 on ${TAILSCALE_IFACE} missing (terminal may not be in use)"
  fi

  # Check port 8000 is listening (any address — UFW restricts which interfaces can reach it)
  local bound_8000
  bound_8000="$(ss -tlnp 2>/dev/null | grep ':8000 ' || true)"
  if [[ -n "${bound_8000}" ]]; then
    record "PASS" "coolify: port 8000 listening"
  else
    record "INFO" "coolify: port 8000" "not yet listening (Coolify may still be starting)"
  fi

  # Note: nc-based self-connect tests cannot validate public exposure — the kernel
  # routes server→own-public-IP locally, bypassing UFW INPUT rules entirely.
  # Public port exposure is validated via UFW rule inspection in ufw_check() instead.
  # External connectivity tests (Gate E/F in deploy.sh) verify from the operator machine.

  # Verify UFW binding-guard timer is active (periodically re-applies UFW rules if removed)
  if systemctl is-active --quiet coolify-binding-guard.timer 2>/dev/null; then
    record "PASS" "coolify: UFW binding-guard timer active"
  else
    record "FAIL" "coolify: UFW binding-guard timer" "not active — UFW rule drift may go undetected"
  fi
}

# ── unattended-upgrades coverage ──

unattended_upgrades_check() {
  local apt_local="/etc/apt/apt.conf.d/52unattended-upgrades-local"
  if [[ ! -f "${apt_local}" ]]; then
    record "FAIL" "auto-updates: local config" "not found at ${apt_local}"
    return
  fi

  if grep -q "Ubuntu" "${apt_local}"; then
    record "PASS" "auto-updates: Ubuntu origin covered"
  else
    record "FAIL" "auto-updates: Ubuntu origin" "not in origins pattern"
  fi

  if grep -q "Docker" "${apt_local}"; then
    record "PASS" "auto-updates: Docker CE origin covered"
  else
    record "FAIL" "auto-updates: Docker CE origin" "missing — Docker packages not auto-updated"
  fi

  if grep -q 'Unattended-Upgrade::Automatic-Reboot' "${apt_local}"; then
    record "PASS" "auto-updates: reboot policy configured"
  else
    record "FAIL" "auto-updates: reboot policy" "not configured"
  fi

  # Functional: verify apt timers are actually running (config alone doesn't prove execution).
  for timer in apt-daily.timer apt-daily-upgrade.timer; do
    if systemctl is-active --quiet "${timer}" 2>/dev/null; then
      record "PASS" "auto-updates: ${timer} active"
    else
      record "FAIL" "auto-updates: ${timer}" "timer not active — unattended-upgrades will not run"
    fi
  done
}

# ── Listening ports (informational) ──

listening_ports_info() {
  local ports
  ports="$(ss -tlnp 2>/dev/null | tail -n +2 | awk '{print $4}' | sort -u)" || true
  if [[ -n "${ports}" ]]; then
    record "INFO" "listening: TCP ports" "$(echo "${ports}" | tr '\n' ' ')"
  fi
}

# ── Coolify SSH access to localhost ──
# Gate-C safe: all checks are skipped if Coolify is not yet installed.

coolify_ssh_check() {
  local ssh_dir="/data/coolify/ssh/keys"

  # Skip entirely if Coolify hasn't been installed yet (Gate C runs before install)
  if [[ ! -d "${ssh_dir}" ]]; then
    return 0
  fi

  local keyfile
  keyfile=$(ls "${ssh_dir}"/ssh_key@* 2>/dev/null | head -1 || true)
  if [[ -z "${keyfile}" ]]; then
    record "FAIL" "coolify: ssh key exists" "no ssh_key@* found in ${ssh_dir}"
    return 0
  fi
  record "PASS" "coolify: ssh key exists"

  # Derive the public key from the private key
  local pubkey
  pubkey=$(ssh-keygen -y -f "${keyfile}" 2>/dev/null || true)
  if [[ -z "${pubkey}" ]]; then
    record "FAIL" "coolify: ssh key readable" "ssh-keygen -y failed on ${keyfile}"
    return 0
  fi

  # Check authorized_keys exists and contains the key on its own line.
  # Match on key data (field 2) only — sshd ignores comment field 3+, and ssh-keygen -y
  # may output a different comment than what was written. A bare substring grep would
  # still match a concatenated line, so we compare against per-line field 2 extractions.
  local auth="/root/.ssh/authorized_keys"
  if [[ ! -f "${auth}" ]]; then
    record "FAIL" "coolify: key in root authorized_keys" "${auth} does not exist"
    return 0
  fi

  local key_data
  key_data=$(awk '{print $2}' <<< "${pubkey}")

  if awk '{print $2}' "${auth}" 2>/dev/null | grep -qxF "${key_data}"; then
    record "PASS" "coolify: key in root authorized_keys"
  else
    # Check for concatenation: key data appears but not as a standalone field
    if grep -qF "${key_data}" "${auth}" 2>/dev/null; then
      record "FAIL" "coolify: key in root authorized_keys" \
        "key present but not on its own line (concatenation bug) — rewrite ${auth}"
    else
      record "FAIL" "coolify: key in root authorized_keys" \
        "Coolify public key not found in ${auth}"
    fi
    return 0
  fi

  # Functional test 1: SSH as root to 127.0.0.1 using Coolify's key (host-side).
  # Tests key + sshd Match block from the host loopback perspective.
  if ssh \
      -o StrictHostKeyChecking=no \
      -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=5 \
      -o BatchMode=yes \
      -o LogLevel=ERROR \
      -i "${keyfile}" \
      root@127.0.0.1 'exit 0' 2>/dev/null; then
    record "PASS" "coolify: root@127.0.0.1 SSH functional"
  else
    record "FAIL" "coolify: root@127.0.0.1 SSH functional" \
      "key auth failed — check sshd Match block and authorized_keys"
  fi

  # Functional test 2: SSH from INSIDE the coolify container to host.docker.internal.
  # This is the exact path Coolify uses for 'This Machine'. Catches:
  #   - host.docker.internal not resolving (host-gateway bug on Linux Docker)
  #   - UFW blocking port 22 from Docker bridge subnet (10.0.0.0/8)
  #   - sshd Match block not covering the Docker bridge address range
  if command -v docker >/dev/null 2>&1 && docker inspect coolify >/dev/null 2>&1; then
    local container_keyfile
    container_keyfile="/var/www/html/storage/app/ssh/keys/$(basename "${keyfile}")"
    if docker exec coolify \
        sh -c "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
               -o ConnectTimeout=5 -o BatchMode=yes -o LogLevel=ERROR \
               -i '${container_keyfile}' root@host.docker.internal 'exit 0'" \
        2>/dev/null; then
      record "PASS" "coolify: container→host SSH via host.docker.internal"
    else
      record "FAIL" "coolify: container→host SSH via host.docker.internal" \
        "SSH from coolify container failed — check host.docker.internal in /etc/hosts, UFW 10.0.0.0/8 rule, and sshd Match block"
    fi
  else
    record "INFO" "coolify: container→host SSH" "coolify container not running; skipped"
  fi
}

# ── cloudflared ──

cloudflared_check() {
  # Not installed at all — that is fine before Coolify deploy
  if ! systemctl list-unit-files --no-legend cloudflared.service 2>/dev/null | grep -q cloudflared \
      && ! command -v cloudflared >/dev/null 2>&1; then
    record "INFO" "cloudflared: not installed"
    return
  fi

  # Installed — now it must be active
  if systemctl is-active --quiet cloudflared 2>/dev/null; then
    record "PASS" "cloudflared: service active"
  else
    local svc_state
    svc_state="$(systemctl is-active cloudflared 2>/dev/null || echo "unknown")"
    record "FAIL" "cloudflared: service active" "state is ${svc_state}"
    return
  fi

  # Config file checks
  local config_file="/etc/cloudflared/config.yml"
  if [[ ! -f "${config_file}" ]]; then
    record "FAIL" "cloudflared: config file" "${config_file} not found"
    return
  fi

  local tunnel_id
  tunnel_id="$(grep -m1 '^tunnel:' "${config_file}" | awk '{print $2}' || true)"
  if [[ -n "${tunnel_id}" ]]; then
    record "PASS" "cloudflared: tunnel ID configured"
  else
    record "FAIL" "cloudflared: tunnel ID" "not found in ${config_file}"
  fi

  # Dashboard hostname must route to Coolify on port 8000
  if grep -qE 'localhost:8000|127\.0\.0\.1:8000' "${config_file}"; then
    record "PASS" "cloudflared: ingress routes dashboard (port 8000)"
  else
    local ingress_svc
    ingress_svc="$(grep -m1 '^\s*service:' "${config_file}" | awk '{print $2}' || true)"
    record "FAIL" "cloudflared: ingress dashboard" \
      "expected localhost:8000 for dashboard, got '${ingress_svc:-unknown}' — re-run deploy to fix"
  fi

  # Wildcard app domains must route to Traefik (coolify-proxy) on port 80, not port 8000.
  # Port 8000 is the Coolify dashboard — routing wildcards there causes all app domains
  # to show the dashboard instead of the actual app.
  if grep -qE 'localhost:80$|localhost:80[^0-9]|127\.0\.0\.1:80$|127\.0\.0\.1:80[^0-9]' "${config_file}"; then
    record "PASS" "cloudflared: ingress routes apps via Traefik (port 80)"
  else
    record "FAIL" "cloudflared: ingress app routing" \
      "no localhost:80 route — app domains will show dashboard instead of apps"
  fi

  # Soketi WebSocket route (ws.DOMAIN → localhost:6001)
  if grep -qE 'localhost:6001|127\.0\.0\.1:6001' "${config_file}"; then
    record "PASS" "cloudflared: ingress routes Soketi (port 6001)"
  else
    record "FAIL" "cloudflared: ingress Soketi" \
      "no localhost:6001 route — WebSocket real-time service unreachable via tunnel"
  fi

  # Terminal route (DOMAIN/terminal/ws → localhost:6002, path-based on dashboard hostname).
  # Must use path-based routing on the dashboard hostname, NOT a separate terminal.DOMAIN hostname.
  # Coolify's terminal WebSocket connects to /terminal/ws on the same origin as the dashboard.
  if grep -qE 'localhost:6002|127\.0\.0\.1:6002' "${config_file}"; then
    if grep -q '/terminal/ws' "${config_file}"; then
      record "PASS" "cloudflared: ingress routes terminal (port 6002 via /terminal/ws path)"
    else
      record "FAIL" "cloudflared: ingress terminal path" \
        "localhost:6002 route exists but missing 'path: /terminal/ws' — terminal uses path-based routing on dashboard hostname, not a separate hostname"
    fi
  else
    record "FAIL" "cloudflared: ingress terminal" \
      "no localhost:6002 route — terminal service unreachable via tunnel"
  fi

  # Ingress order: terminal path rule must appear BEFORE the dashboard catch-all.
  # cloudflared matches first-match — if DOMAIN→:8000 comes before DOMAIN+path→:6002,
  # the path rule never fires and the terminal WebSocket breaks.
  local terminal_line dashboard_line
  terminal_line="$(grep -nE 'localhost:6002|127\.0\.0\.1:6002' "${config_file}" | head -1 | cut -d: -f1 || true)"
  dashboard_line="$(grep -nE 'localhost:8000|127\.0\.0\.1:8000' "${config_file}" | head -1 | cut -d: -f1 || true)"
  if [[ -n "${terminal_line}" && -n "${dashboard_line}" ]]; then
    if (( terminal_line < dashboard_line )); then
      record "PASS" "cloudflared: terminal rule before dashboard (correct ingress order)"
    else
      record "FAIL" "cloudflared: ingress order" \
        "terminal (line ${terminal_line}) must appear before dashboard (line ${dashboard_line}) — cloudflared is first-match"
    fi
  fi

  # Functional connectivity: probe cloudflared's /ready endpoint.
  # The metrics port is not always 2000; newer cloudflared picks an ephemeral port or
  # uses a management socket. Discover the port dynamically from ss/procfs, then probe it.
  local cf_pid cf_port
  cf_pid="$(pgrep -x cloudflared | head -1 || true)"
  if [[ -n "${cf_pid}" ]]; then
    cf_port="$(ss -tlnp 2>/dev/null \
      | awk -v pid="${cf_pid}" 'index($0,"pid="pid",") && /127\.0\.0\.1:/{print $4}' \
      | awk -F: '{print $NF}' | head -1 || true)"
  fi

  if [[ -n "${cf_port:-}" ]] && curl -sf --max-time 3 "http://127.0.0.1:${cf_port}/ready" >/dev/null 2>&1; then
    local ready_json conn_count
    ready_json="$(curl -sf --max-time 3 "http://127.0.0.1:${cf_port}/ready" 2>/dev/null || true)"
    conn_count="$(printf '%s' "${ready_json}" | python3 -c \
      "import sys,json; print(json.load(sys.stdin).get('readyConnections',0))" 2>/dev/null || echo "?")"
    record "PASS" "cloudflared: tunnel /ready OK (${conn_count} connections)"
  elif [[ -n "${cf_port:-}" ]]; then
    record "FAIL" "cloudflared: tunnel /ready" \
      "port ${cf_port} not responding — tunnel may be disconnected"
  else
    record "INFO" "cloudflared: tunnel /ready" \
      "could not determine cloudflared metrics port — manual check needed"
  fi
}

# ── Coolify container health ──
# Gate-C safe: skips entirely if /data/coolify does not exist.

coolify_container_check() {
  if ! command -v docker >/dev/null 2>&1; then
    record "INFO" "coolify-containers: docker" "Docker not installed; skipped"
    return
  fi

  if [[ ! -d "/data/coolify" ]]; then
    return 0
  fi

  local containers=("coolify" "coolify-db" "coolify-redis" "coolify-proxy")
  for ctr in "${containers[@]}"; do
    local state health
    state="$(docker inspect --format '{{.State.Status}}' "${ctr}" 2>/dev/null || echo "not-found")"
    if [[ "${state}" == "not-found" ]]; then
      # proxy may genuinely be absent if no apps deployed yet — info not fail
      if [[ "${ctr}" == "coolify-proxy" ]]; then
        record "INFO" "coolify-containers: ${ctr}" "not found (normal before first app deploy)"
      else
        record "FAIL" "coolify-containers: ${ctr}" "container not found"
      fi
      continue
    fi

    if [[ "${state}" != "running" ]]; then
      record "FAIL" "coolify-containers: ${ctr} running" "state is ${state}"
      continue
    fi

    # Check healthcheck status if configured (some containers have none)
    health="$(docker inspect \
      --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' \
      "${ctr}" 2>/dev/null || echo "unknown")"

    case "${health}" in
      healthy|no-healthcheck)
        record "PASS" "coolify-containers: ${ctr} running (${health})" ;;
      starting)
        record "INFO" "coolify-containers: ${ctr}" "healthcheck still starting — re-run in a minute" ;;
      *)
        record "FAIL" "coolify-containers: ${ctr} health" "${health}" ;;
    esac
  done
}

# ── Hardening validation timer ──

validate_timer_check() {
  if systemctl is-active --quiet hardening-validate.timer 2>/dev/null; then
    record "PASS" "validate-timer: active"
  elif systemctl list-unit-files --no-legend hardening-validate.timer 2>/dev/null | grep -q hardening-validate; then
    record "FAIL" "validate-timer: active" "timer installed but not active"
  else
    record "INFO" "validate-timer: not installed" "run bootstrap to install"
  fi
}

# ── Run all checks ──

[[ "$(id -u)" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }

if [[ "${JSON_MODE}" == "false" && "${HEALTH_CHECK_MODE}" != "true" ]]; then
  printf '%-6s %-45s %s\n' "STATUS" "CHECK" "DETAIL"
  printf '%s\n' "--------------------------------------------------------------"
fi

ssh_check
ufw_check
docker_user_check
docker_user_lifecycle_check
docker_daemon_check
sysctl_check
fail2ban_check
auditd_check
unattended_upgrades_check
journald_check
timesync_check
swap_check
banner_check
admin_sudo_check
apparmor_check
disabled_services_check
tailscale_check
coolify_binding_check
coolify_ssh_check
coolify_container_check
validate_timer_check
listening_ports_info
cloudflared_check

# ── Summary ──

if [[ "${HEALTH_CHECK_MODE}" == "true" ]]; then
  if ((FAIL_COUNT > 0)); then
    echo "UNHEALTHY"
    exit 1
  fi
  echo "HEALTHY"
  exit 0
elif [[ "${JSON_MODE}" == "true" ]]; then
  printf '{"pass":%d,"fail":%d,"info":%d,"checks":[' "${PASS_COUNT}" "${FAIL_COUNT}" "${INFO_COUNT}"
  first="true"
  for r in "${RESULTS[@]}"; do
    if [[ "${first}" == "true" ]]; then
      first="false"
    else
      printf ','
    fi
    printf '%s' "${r}"
  done
  printf ']}\n'
else
  printf '%s\n' "--------------------------------------------------------------"
  printf 'Summary: %d PASS, %d FAIL, %d INFO\n' "${PASS_COUNT}" "${FAIL_COUNT}" "${INFO_COUNT}"
fi

if ((FAIL_COUNT > 0)); then
  exit 1
fi
exit 0
