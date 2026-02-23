#!/usr/bin/env bash
set -Eeuo pipefail

# configure_coolify_binding.sh — Bind Coolify dashboard to Tailscale IP only
# Companion script for bootstrap_hardening.sh
#
# Usage:
#   sudo ./configure_coolify_binding.sh [--tailscale-ip <ip>] [--dry-run]

SCRIPT_NAME="$(basename "$0")"
COOLIFY_ENV="/data/coolify/source/.env"
TAILSCALE_IP=""
DRY_RUN="false"

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

usage() {
  cat <<'EOF'
Bind Coolify dashboard and Soketi to Tailscale IP only.

Usage:
  configure_coolify_binding.sh [options]

Options:
  --tailscale-ip <ip>   Override auto-detected Tailscale IPv4 address
  --dry-run             Print actions without changing system
  -h, --help            Show this help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tailscale-ip)
      [[ -n "${2:-}" ]] || die "Option $1 requires a value."
      TAILSCALE_IP="$2"
      shift 2
      ;;
    --dry-run)
      DRY_RUN="true"
      shift
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

# Require root
[[ "$(id -u)" -eq 0 ]] || die "Run as root."

# Auto-detect Tailscale IP if not provided
if [[ -z "${TAILSCALE_IP}" ]]; then
  command -v tailscale >/dev/null 2>&1 || die "tailscale command not found. Install Tailscale or use --tailscale-ip."
  TAILSCALE_IP="$(tailscale ip -4 2>/dev/null)" || die "Failed to detect Tailscale IPv4 address. Is Tailscale running?"
  [[ -n "${TAILSCALE_IP}" ]] || die "Tailscale returned empty IPv4 address."
fi

# Validate IP format
[[ "${TAILSCALE_IP}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Invalid IP address format: ${TAILSCALE_IP}"

log "Using Tailscale IP: ${TAILSCALE_IP}"

# Check Coolify env file exists
[[ -f "${COOLIFY_ENV}" ]] || die "Coolify .env file not found at ${COOLIFY_ENV}. Is Coolify installed?"

if [[ "${DRY_RUN}" == "true" ]]; then
  log "DRY-RUN: would add UFW rules for ports 8000, 6001, and 6002 on tailscale0"
  log "DRY-RUN: would verify Coolify is listening on port 8000"
  log "DRY-RUN: would verify port 8000 is not reachable on public IP"
  exit 0
fi

# Dashboard security is enforced via UFW, not APP_PORT socket binding.
# Coolify's docker-compose.prod.yml uses APP_PORT in both ports: and expose: directives;
# expose: requires a plain port number, so IP:port format is incompatible.
# UFW default-deny incoming + explicit allow rules on tailscale0 provides the same
# defense-in-depth: dashboard reachable via Tailscale, blocked from public interfaces.

log "Ensuring UFW rules allow dashboard on Tailscale interface..."
if command -v ufw >/dev/null 2>&1; then
  # Idempotent — ufw silently skips duplicate rules
  ufw allow in on tailscale0 proto tcp to any port 8000 comment "coolify-hardening-dashboard-tailscale" 2>/dev/null || true
  ufw allow in on tailscale0 proto tcp to any port 6001 comment "coolify-hardening-soketi-tailscale" 2>/dev/null || true
  ufw allow in on tailscale0 proto tcp to any port 6002 comment "coolify-hardening-terminal-tailscale" 2>/dev/null || true
  log "UFW rules applied for ports 8000, 6001, and 6002 on tailscale0."
else
  warn "ufw not found — skipping UFW rule check."
fi

# Wait for Coolify to start (up to 60s)
log "Waiting for Coolify to bind port 8000 (up to 60s)..."
for (( _i = 1; _i <= 12; _i++ )); do
  if ss -tlnp 2>/dev/null | grep -q ':8000 '; then
    break
  fi
  sleep 5
done
unset _i

# Verify security posture
log "Verifying bindings..."

local bound_8000 bound_6001 bound_6002 public_ip
bound_8000="$(ss -tlnp 2>/dev/null | grep ':8000 ' || true)"
if [[ -n "${bound_8000}" ]]; then
  log "PASS: Port 8000 is listening"
else
  warn "Port 8000 not yet listening. Check: ss -tlnp | grep 8000"
fi

bound_6001="$(ss -tlnp 2>/dev/null | grep ':6001 ' || true)"
if [[ -n "${bound_6001}" ]]; then
  log "PASS: Port 6001 is listening"
else
  warn "Port 6001 not yet listening (may start later). Check: ss -tlnp | grep 6001"
fi

bound_6002="$(ss -tlnp 2>/dev/null | grep ':6002 ' || true)"
if [[ -n "${bound_6002}" ]]; then
  log "PASS: Port 6002 is listening"
else
  warn "Port 6002 not yet listening (may start later). Check: ss -tlnp | grep 6002"
fi

# Test that public IP is NOT serving the dashboard (UFW should block it)
if command -v nc >/dev/null 2>&1; then
  public_ip="$(ip -o route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}')"
  if [[ -n "${public_ip}" && "${public_ip}" != "${TAILSCALE_IP}" ]]; then
    if nc -z -w2 "${public_ip}" 8000 2>/dev/null; then
      warn "Port 8000 still appears reachable on public IP ${public_ip}. Check UFW rules."
    else
      log "PASS: Port 8000 not reachable on public IP ${public_ip}"
    fi
  fi
fi

log "Coolify binding configuration complete."
log "Dashboard accessible at: http://${TAILSCALE_IP}:8000 (via Tailscale)"
