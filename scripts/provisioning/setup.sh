#!/usr/bin/env bash
set -Eeuo pipefail

# setup.sh — Server-side orchestrator for secure Coolify deployment
# Run directly on the server (after SSH'ing in manually).
#
# Interactive mode:  sudo ./setup.sh
# Non-interactive:   sudo ./setup.sh --server-ip 1.2.3.4 --admin-user ... --yes
# Mixed:             sudo ./setup.sh --server-ip 1.2.3.4  (prompted for the rest)

SCRIPT_NAME="$(basename "$0")"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# shellcheck source=lib/coolify-common.sh
source "${SCRIPT_DIR}/lib/coolify-common.sh"

# ── Inputs (populated by flags or prompts) ──────────────────────────────────

SERVER_IP="${SERVER_IP:-}"
ADMIN_USER="${ADMIN_USER:-}"
PUBKEY_FILE="${PUBKEY_FILE:-}"
TAILSCALE_AUTH_KEY="${TAILSCALE_AUTH_KEY:-}"
DEPLOY_MODE="${DEPLOY_MODE:-}"
DOMAIN="${DOMAIN:-}"
CF_API_TOKEN="${CF_API_TOKEN:-}"
CF_ZONE="${CF_ZONE:-}"
APP_DOMAIN_MODE="${APP_DOMAIN_MODE:-}"
SWAP_SIZE="${SWAP_SIZE:-}"
AUTO_YES="${AUTO_YES:-false}"

# ── Derived at runtime ──────────────────────────────────────────────────────

ADMIN_PUBKEY=""
TS_IP=""
CF_ZONE_ID=""
CF_ZONE_NAME=""
APP_DOMAIN=""
CF_ACCOUNT_ID=""
TUNNEL_ID=""
TUNNEL_SECRET=""

pause_for_operator() {
  if is_true "${AUTO_YES}"; then return 0; fi
  local msg="$1"
  printf '\n  \033[1;33m⏸  %s\033[0m\n' "${msg}"
  printf '  Press Enter when ready...'
  read -r
}

# ── Usage ───────────────────────────────────────────────────────────────────

usage() {
  cat <<'EOF'
setup.sh — Server-side orchestrator for secure Coolify deployment

Usage:
  sudo setup.sh [options]

Run this directly on the server. If all required flags are provided,
runs non-interactively. If any are missing, prompts for them.

Required:
  --server-ip <ip>              Server public IPv4 address
  --admin-user <name>           Admin username
  --pubkey-file <path>          SSH public key file (on this server)
  --tailscale-auth-key <key>    Tailscale auth key (tskey-auth-...)
  --domain <fqdn>               Domain name for Coolify
  --cf-api-token <token>        Cloudflare API token

Optional:
  --mode <tunnel|standard>       Deployment mode (default: tunnel)
  --app-domain-mode <vps|apex>  App subdomain scope: vps=appname.DOMAIN, apex=appname.ZONE (default: apex)
  --cf-zone <zone>              Cloudflare zone (default: derived from domain)
  --swap-size <size>            Swap size (default: 2G)
  --yes                         Skip confirmation prompts (for automation)
  -h, --help                    Show this help
EOF
}

# ── Argument parsing ────────────────────────────────────────────────────────

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --server-ip)       SERVER_IP="${2:?--server-ip requires a value}"; shift 2 ;;
      --admin-user)      ADMIN_USER="${2:?--admin-user requires a value}"; shift 2 ;;
      --pubkey-file)     PUBKEY_FILE="${2:?--pubkey-file requires a value}"; shift 2 ;;
      --tailscale-auth-key) TAILSCALE_AUTH_KEY="${2:?--tailscale-auth-key requires a value}"; shift 2 ;;
      --mode)            DEPLOY_MODE="${2:?--mode requires a value}"; shift 2 ;;
      --domain)          DOMAIN="${2:?--domain requires a value}"; shift 2 ;;
      --cf-api-token)    CF_API_TOKEN="${2:?--cf-api-token requires a value}"; shift 2 ;;
      --cf-zone)         CF_ZONE="${2:?--cf-zone requires a value}"; shift 2 ;;
      --app-domain-mode) APP_DOMAIN_MODE="${2:?--app-domain-mode requires a value}"; shift 2 ;;
      --swap-size)       SWAP_SIZE="${2:?--swap-size requires a value}"; shift 2 ;;
      --yes)             AUTO_YES="true"; shift ;;
      -h|--help)         usage; exit 0 ;;
      *)                 die "Unknown option: $1 (use --help)" ;;
    esac
  done
}

# ── Input collection (flag → prompt fallback) ──────────────────────────────

collect_inputs() {
  collect_common_inputs
}

# ── Input validation ───────────────────────────────────────────────────────

validate_inputs() {
  [[ "$(id -u)" -eq 0 ]] || die "This script must be run as root (use sudo)."
  [[ "${SERVER_IP}" =~ ${IPV4_RE} ]]      || die "Invalid server IP: ${SERVER_IP}"
  [[ "${ADMIN_USER}" =~ ${LINUX_USER_RE} ]] || die "Invalid admin username: ${ADMIN_USER}"
  [[ "${ADMIN_USER}" != "root" ]]          || die "Admin user must not be root."

  [[ -f "${PUBKEY_FILE}" ]]                || die "Public key file not found: ${PUBKEY_FILE}"
  ssh-keygen -l -f "${PUBKEY_FILE}" >/dev/null 2>&1 \
    || die "Invalid SSH public key: ${PUBKEY_FILE}"
  ADMIN_PUBKEY="$(cat "${PUBKEY_FILE}")"

  [[ "${TAILSCALE_AUTH_KEY}" == tskey-auth-* ]] \
    || die "Tailscale auth key must start with 'tskey-auth-' (got: ${TAILSCALE_AUTH_KEY:0:12}...)"

  [[ "${DEPLOY_MODE}" == "standard" || "${DEPLOY_MODE}" == "tunnel" ]] \
    || die "Mode must be 'standard' or 'tunnel' (got: ${DEPLOY_MODE})"

  [[ "${APP_DOMAIN_MODE}" == "vps" || "${APP_DOMAIN_MODE}" == "apex" ]] \
    || die "App domain mode must be 'vps' or 'apex' (got: ${APP_DOMAIN_MODE})"

  [[ "${DOMAIN}" =~ ${FQDN_RE} ]]         || die "Invalid domain: ${DOMAIN}"
  [[ -n "${CF_API_TOKEN}" ]]               || die "Cloudflare API token is required."
  [[ "${SWAP_SIZE}" =~ ${SWAP_RE} ]]       || die "Invalid swap size: ${SWAP_SIZE} (expected e.g. 2G, 512M)"

  # Verify scripts are present in current directory
  local scripts=(bootstrap_hardening.sh validate_hardening.sh configure_coolify_binding.sh)
  for script in "${scripts[@]}"; do
    [[ -f "${SCRIPT_DIR}/${script}" ]] || die "Required script not found: ${SCRIPT_DIR}/${script}"
  done
}

verify_docker_user_gate_local() {
  local gate_label="$1"
  local gate_d_inactive_msg="Gate D failed: docker-user-hardening.service is not active."

  if systemctl is-active --quiet docker-user-hardening.service; then
    pass "${gate_label}: docker-user-hardening.service is active"
  else
    fail "${gate_label}: docker-user-hardening.service is not active"
    die "${gate_d_inactive_msg}"
  fi

  local iptables_out
  iptables_out="$(iptables -S DOCKER-USER 2>/dev/null)" || true
  if printf '%s' "${iptables_out}" | grep -q "coolify-hardening"; then
    pass "${gate_label}: DOCKER-USER hardening rules active"
  else
    fail "${gate_label}: DOCKER-USER hardening rules not found"
    die "${gate_label} failed. Check: systemctl status docker-user-hardening.service"
  fi
}

reconcile_docker_daemon_local() {
  log "Reconciling Docker daemon settings after Coolify install..."
  # Hardening owns: log-driver, log-opts, live-restore. Coolify may add: default-address-pools.
  # Using json-file driver to match Coolify's expectation for compatibility.
  local daemon_json="/etc/docker/daemon.json"
  local tmp
  tmp="$(mktemp)"

  # Drift detection: warn if hardening keys were changed (e.g., by Coolify update)
  if [[ -f "${daemon_json}" ]]; then
    local current_driver current_live_restore
    current_driver="$(jq -r '.["log-driver"] // ""' "${daemon_json}" 2>/dev/null || true)"
    if [[ "${current_driver}" != "" && "${current_driver}" != "json-file" ]]; then
      warn "Docker log-driver drift detected (was '${current_driver}', expected 'json-file'). Reconciling..."
    fi
    current_live_restore="$(jq -r '.["live-restore"] // ""' "${daemon_json}" 2>/dev/null || true)"
    if [[ "${current_live_restore}" != "" && "${current_live_restore}" != "true" ]]; then
      warn "Docker live-restore drift detected (was '${current_live_restore}', expected 'true'). Reconciling..."
    fi
  fi

  if [[ -f "${daemon_json}" ]]; then
    jq '. + {"log-driver":"json-file","log-opts":((.["log-opts"] // {}) + {"max-size":"10m","max-file":"3"}),"live-restore":true}' "${daemon_json}" > "${tmp}"
  else
    jq -n '{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"},"live-restore":true}' > "${tmp}"
  fi

  if [[ -f "${daemon_json}" ]] && cmp -s "${tmp}" "${daemon_json}"; then
    rm -f "${tmp}"
    pass "Docker daemon settings already match hardening policy"
    return 0
  fi

  if [[ -f "${daemon_json}" ]]; then
    cp -a "${daemon_json}" "${daemon_json}.bak.$(date +%s)"
  fi

  cat "${tmp}" > "${daemon_json}"
  chmod 0644 "${daemon_json}"
  rm -f "${tmp}"
  systemctl restart docker
  pass "Docker daemon hardening reconciled (json-file log rotation + live-restore)"
}

# ── Pre-flight ──────────────────────────────────────────────────────────────

preflight() {
  step "0/5" "Pre-flight checks"

  # Check local tools
  local required_cmds=(curl jq ssh-keygen openssl)
  for cmd in "${required_cmds[@]}"; do
    command -v "${cmd}" >/dev/null 2>&1 || die "Required command not found: ${cmd}. Install it first."
  done
  pass "Required tools present"

  # Validate pubkey
  ssh-keygen -l -f "${PUBKEY_FILE}" >/dev/null 2>&1 || die "Invalid SSH public key file: ${PUBKEY_FILE}"
  pass "SSH public key valid: ${PUBKEY_FILE}"

  # Verify Cloudflare token
  cf_verify_token
  cf_get_zone_id
  cf_get_account_id  # always fetch — needed for tunnel (default mode)
  resolve_app_domain
  pass "Cloudflare API verified (zone: ${CF_ZONE_ID})"
}

# ── Phase 1: Harden ────────────────────────────────────────────────────────

phase1_harden() {
  step "1/5" "Harden server"

  local tunnel_flag="false"
  [[ "${DEPLOY_MODE}" == "tunnel" ]] && tunnel_flag="true"

  # Write env file (avoids quoting issues with pubkey)
  cat > /root/deploy.env <<EOF
ADMIN_USER=${ADMIN_USER}
ADMIN_PUBKEY="${ADMIN_PUBKEY}"
TAILSCALE_CIDR=100.64.0.0/10
SSH_PORT=22
TUNNEL_MODE=${tunnel_flag}
SWAP_SIZE=${SWAP_SIZE}
INSTALL_TAILSCALE=true
TAILSCALE_AUTH_KEY=${TAILSCALE_AUTH_KEY}
BIND_DASHBOARD_TO_TAILSCALE=false
EOF
  chmod 600 /root/deploy.env
  pass "Environment file written"

  # Run hardening
  log "Running bootstrap_hardening.sh (this may take a few minutes)..."
  "${SCRIPT_DIR}/bootstrap_hardening.sh" --env-file /root/deploy.env --install-tailscale --force \
    || die "bootstrap_hardening.sh failed. Check: /var/log/bootstrap-hardening.log"
  pass "Hardening completed"

  # Capture Tailscale IP
  TS_IP="$(tailscale ip -4 2>/dev/null | tr -d '[:space:]')"
  [[ -n "${TS_IP}" ]] || die "Failed to get Tailscale IP."
  pass "Server Tailscale IP: ${TS_IP}"

  # Clean up sensitive env file
  rm -f /root/deploy.env
}

# ── Phase 2: Gate checks ───────────────────────────────────────────────────

phase2_gates() {
  step "2/5" "Gate checks"

  # Gate A: Operator verifies SSH from laptop
  pause_for_operator "From your LAPTOP, verify SSH: ssh ${ADMIN_USER}@${TS_IP} (Tailscale IP)"

  # Gate B: Verify admin user is functional locally
  local admin_home
  admin_home="$(getent passwd "${ADMIN_USER}" | cut -d: -f6 2>/dev/null)" || true
  if [[ -n "${admin_home}" ]] && [[ -d "${admin_home}/.ssh" ]]; then
    pass "Gate B: Admin user ${ADMIN_USER} exists with SSH dir"
  else
    fail "Gate B: Admin user ${ADMIN_USER} home or .ssh not found"
    die "Gate B failed."
  fi

  # Gate C: Validation passes
  log "Gate C: Running validate_hardening.sh..."
  local validate_json
  validate_json="$("${SCRIPT_DIR}/validate_hardening.sh" --json 2>/dev/null)" || true
  report_validation_result "Gate C" "${validate_json}" \
    "Gate C failed. Fix validation failures before continuing."
}

# ── Phase 3: Docker + Coolify ──────────────────────────────────────────────

phase3_docker_coolify() {
  step "3/5" "Install Docker & Coolify"

  # Install Docker (skip if already present — the install script is not idempotent on network errors)
  if docker version >/dev/null 2>&1; then
    log "Docker already installed — skipping install."
  else
    log "Installing Docker..."
    curl -fsSL https://get.docker.com | sh \
      || die "Docker installation failed."
    pass "Docker installed"
  fi
  pass "Docker present"

  # Start DOCKER-USER hardening service
  systemctl start docker-user-hardening.service \
    || die "Failed to start docker-user-hardening.service"

  # Gate D: Verify DOCKER-USER rules
  verify_docker_user_gate_local "Gate D"

  # Install Coolify (skip if already installed)
  if [[ -f /data/coolify/source/.env ]]; then
    log "Coolify .env found — skipping install (already installed)."
    pass "Coolify already installed"
  else
    log "Installing Coolify (this may take a few minutes)..."
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash \
      || die "Coolify installation failed."
    pass "Coolify installed"
  fi

  # Coolify installer manages daemon.json; re-apply hardening settings while preserving its keys.
  reconcile_docker_daemon_local

  # Docker restart can flush DOCKER-USER runtime rules; re-apply and verify.
  systemctl restart docker-user-hardening.service \
    || die "Failed to restart docker-user-hardening.service after Docker daemon reconciliation."
  verify_docker_user_gate_local "Gate D (post-Coolify)"

  # Add Coolify's generated SSH public key to root's authorized_keys.
  # Required for the Coolify "This Machine" onboarding: Coolify SSHes to localhost as root
  # using its own key. The hardening Match block allows key-only root login from
  # localhost (127.0.0.1), 172.16.0.0/12, and 10.0.0.0/8 (Docker pool); key must be present.
  log "Adding Coolify SSH key to root authorized_keys..."
  local keyfile auth pubkey
  keyfile=$(ls /data/coolify/ssh/keys/ssh_key@* 2>/dev/null | head -1 || true)
  if [[ -z "${keyfile}" ]]; then
    warn "No Coolify SSH key found — skipping root authorized_keys update"
  else
    pubkey=$(ssh-keygen -y -f "${keyfile}")
    auth=/root/.ssh/authorized_keys
    mkdir -p /root/.ssh && chmod 700 /root/.ssh
    touch "${auth}" && chmod 600 "${auth}"
    if grep -qxF "${pubkey}" "${auth}" 2>/dev/null; then
      log "Coolify key already in root authorized_keys"
    else
      # Ensure file ends with newline before appending to avoid key concatenation
      [[ -s "${auth}" ]] && [[ "$(tail -c1 "${auth}" | od -An -tx1 | tr -d ' \n')" != "0a" ]] \
        && printf '\n' >> "${auth}"
      printf '%s\n' "${pubkey}" >> "${auth}"
    fi
    pass "Coolify SSH key in root authorized_keys"
  fi

  # Fix host.docker.internal resolution on Linux Docker.
  # Docker on Linux doesn't resolve host-gateway to a real IP in all versions/configurations.
  # Patch Coolify's docker-compose.yml to use the actual coolify network gateway IP,
  # then recreate the container so the fix takes effect.
  log "Fixing host.docker.internal for Linux Docker..."
  local compose_yml="/data/coolify/source/docker-compose.yml"
  if [[ -f "${compose_yml}" ]]; then
    local gateway
    gateway=$(docker network inspect coolify --format '{{range .IPAM.Config}}{{.Subnet}} {{.Gateway}} {{end}}' 2>/dev/null \
      | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | grep -v '/[0-9]' | head -1 || true)

    if [[ -z "${gateway}" ]]; then
      warn "Cannot determine coolify network gateway — skipping host.docker.internal fix"
    else
      local current
      current=$(grep -m1 'host\.docker\.internal:' "${compose_yml}" | awk -F: '{print $NF}' | tr -d ' ' || true)
      if [[ "${current}" == "${gateway}" ]]; then
        log "host.docker.internal already set to ${gateway}"
      else
        sed -i "s|host\.docker\.internal:.*|host.docker.internal:${gateway}|g" "${compose_yml}"
        log "Patched host.docker.internal → ${gateway}"
        docker compose -f /data/coolify/source/docker-compose.yml \
                       -f /data/coolify/source/docker-compose.prod.yml \
                       up -d --force-recreate coolify soketi 2>&1 | tail -5
      fi
      pass "host.docker.internal patched in Coolify docker-compose"
    fi
  else
    warn "docker-compose.yml not found — skipping host.docker.internal fix"
  fi
}

# ── Phase 4: Binding + DNS ─────────────────────────────────────────────────

phase4_binding_dns() {
  step "4/5" "Configure dashboard binding & DNS"

  # Wait for Coolify to write its .env file before binding (installer is async)
  log "Waiting for Coolify to initialize /data/coolify/source/.env..."
  local coolify_wait=0 coolify_max=120
  until [[ -f /data/coolify/source/.env ]]; do
    (( coolify_wait += 5 ))
    if (( coolify_wait >= coolify_max )); then
      warn "Coolify .env not found after ${coolify_max}s — binding may fail; continuing."
      break
    fi
    sleep 5
  done

  # Run configure_coolify_binding.sh directly
  log "Binding Coolify dashboard to Tailscale IP..."
  "${SCRIPT_DIR}/configure_coolify_binding.sh" --tailscale-ip "${TS_IP}" \
    || warn "configure_coolify_binding.sh returned non-zero (may be ok if Coolify is still starting)"
  pass "Dashboard binding configured"

  # Set Coolify wildcard domain directly in the database.
  # configure_coolify_binding.sh already waited up to 60s for port 8000 to bind,
  # which guarantees the s6 startup sequence (migrate→seed→init) has completed and
  # the server_settings row (server_id=0, the hardcoded Localhost server) exists.
  # The API PATCH /servers/{uuid} does not expose wildcard_domain, so we write
  # directly to PostgreSQL via docker exec on the coolify-db container.
  log "Setting Coolify wildcard domain to http://${APP_DOMAIN}..."
  local coolify_env="/data/coolify/source/.env"
  local db_user db_name db_pass
  db_user="$(grep '^DB_USERNAME=' "${coolify_env}" | cut -d= -f2 || echo 'coolify')"
  db_name="$(grep '^DB_DATABASE=' "${coolify_env}" | cut -d= -f2 || echo 'coolify')"
  db_pass="$(grep '^DB_PASSWORD=' "${coolify_env}" | cut -d= -f2)"
  docker exec -e PGPASSWORD="${db_pass}" coolify-db \
    psql -U "${db_user}" -d "${db_name}" -c \
    "UPDATE server_settings SET wildcard_domain = 'http://${APP_DOMAIN}' WHERE server_id = 0;" \
    2>/dev/null \
    || warn "psql update failed — set Wildcard Domain manually in Coolify UI > Servers > localhost"
  pass "Coolify wildcard domain: http://${APP_DOMAIN}"

  # In tunnel mode, set PUSHER_* env vars so the browser connects to Soketi via the
  # tunnel (wss://ws.DOMAIN) instead of trying to reach localhost:6001 directly.
  # Standard mode does not need this — browser connects to the Tailscale IP on port 6001.
  if [[ "${DEPLOY_MODE}" == "tunnel" ]]; then
    log "Setting PUSHER env vars for tunnel-mode WebSocket routing..."
    local coolify_env="/data/coolify/source/.env"
    # Idempotent: remove existing PUSHER_HOST/PORT/SCHEME lines, then append
    sed -i '/^PUSHER_HOST=/d; /^PUSHER_PORT=/d; /^PUSHER_SCHEME=/d' "${coolify_env}"
    cat >> "${coolify_env}" <<PUSHER_INNER
PUSHER_HOST=ws.${DOMAIN}
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_INNER
    log "PUSHER env vars written to ${coolify_env}"
    # Recreate coolify + soketi to pick up the new env (fast — ~10s, no DB/redis restart)
    docker compose -f /data/coolify/source/docker-compose.yml \
                   -f /data/coolify/source/docker-compose.prod.yml \
                   up -d --force-recreate coolify soketi 2>&1 | tail -5
    pass "PUSHER env vars configured: ws.${DOMAIN}:443 (wss)"
  fi

  if [[ "${DEPLOY_MODE}" == "standard" ]]; then
    # Standard mode: A records pointing to server public IP (proxied)
    log "Configuring DNS: A record ${DOMAIN} → ${SERVER_IP} (proxied)..."
    cf_upsert_a_record "${DOMAIN}" "${SERVER_IP}" "true"
    pass "DNS A record configured: ${DOMAIN} → ${SERVER_IP}"

    # Wildcard A records — always create both scopes so manually set domains at either level work
    local wildcard_name="*.${APP_DOMAIN}"
    log "Configuring DNS: wildcard A record ${wildcard_name} → ${SERVER_IP} (proxied)..."
    cf_upsert_a_record "${wildcard_name}" "${SERVER_IP}" "true"
    pass "DNS wildcard A record configured: ${wildcard_name} → ${SERVER_IP}"
    if [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]]; then
      local apex_wildcard="*.${CF_ZONE_NAME}"
      cf_upsert_a_record "${apex_wildcard}" "${SERVER_IP}" "true"
      pass "DNS wildcard A record configured: ${apex_wildcard} → ${SERVER_IP}"
    fi

  elif [[ "${DEPLOY_MODE}" == "tunnel" ]]; then
    # Tunnel mode: create tunnel, install cloudflared, CNAME
    log "Creating Cloudflare Tunnel..."
    _stop_cloudflared() { systemctl stop cloudflared 2>/dev/null || true; }
    cf_create_tunnel "_stop_cloudflared"
    pass "Tunnel created: ${TUNNEL_ID}"

    # Install cloudflared
    log "Installing cloudflared..."
    apt-get update -qq && apt-get install -y -qq cloudflared 2>/dev/null \
      || {
        log "Trying Cloudflare repository..."
        curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
          | tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
        echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared $(lsb_release -cs) main" \
          | tee /etc/apt/sources.list.d/cloudflared.list
        apt-get update -qq && apt-get install -y -qq cloudflared \
          || die "Failed to install cloudflared"
      }
    pass "cloudflared installed"

    # Write tunnel credentials
    local creds_json
    creds_json="$(jq -n --arg id "${TUNNEL_ID}" --arg secret "${TUNNEL_SECRET}" --arg account "${CF_ACCOUNT_ID}" \
      '{AccountTag:$account,TunnelID:$id,TunnelSecret:$secret}')"
    mkdir -p /etc/cloudflared
    printf '%s' "${creds_json}" > "/etc/cloudflared/${TUNNEL_ID}.json"
    chmod 600 "/etc/cloudflared/${TUNNEL_ID}.json"

    # Write tunnel config — always include both wildcard levels so manually set app domains
    # at either scope (vps or apex) are routed correctly.
    # ws. and terminal. hostnames route Soketi WebSocket and terminal services through the
    # tunnel so the browser can reach them over HTTPS without exposing extra ports.
    local extra_apex_ingress=""
    if [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]]; then
      extra_apex_ingress="  - hostname: \"*.${CF_ZONE_NAME}\"
    service: http://localhost:80
"
    fi
    cat > /etc/cloudflared/config.yml <<EOF
tunnel: ${TUNNEL_ID}
credentials-file: /etc/cloudflared/${TUNNEL_ID}.json

ingress:
  - hostname: ${DOMAIN}
    service: http://localhost:8000
  - hostname: ws.${DOMAIN}
    service: http://localhost:6001
  - hostname: terminal.${DOMAIN}
    service: http://localhost:6002
  - hostname: "*.${APP_DOMAIN}"
    service: http://localhost:80
${extra_apex_ingress}  - service: http_status:404
EOF
    local wc_summary="*.${APP_DOMAIN}"
    [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]] && wc_summary+=" and *.${CF_ZONE_NAME}"
    pass "Tunnel credentials and config written (wildcards: ${wc_summary})"

    # Start cloudflared service
    cloudflared service install 2>/dev/null || true
    systemctl enable --now cloudflared \
      || die "Failed to start cloudflared service"
    pass "cloudflared service running"

    # Create CNAME records: exact domain + wildcard for subdomains
    local tunnel_target="${TUNNEL_ID}.cfargotunnel.com"
    cf_upsert_cname "${DOMAIN}" "${tunnel_target}"
    pass "DNS CNAME configured: ${DOMAIN} → ${tunnel_target}"

    # Always create both wildcard CNAME levels for full routing coverage
    cf_upsert_cname "*.${APP_DOMAIN}" "${tunnel_target}"
    pass "DNS wildcard CNAME configured: *.${APP_DOMAIN} → ${tunnel_target}"
    if [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]]; then
      cf_upsert_cname "*.${CF_ZONE_NAME}" "${tunnel_target}"
      pass "DNS wildcard CNAME configured: *.${CF_ZONE_NAME} → ${tunnel_target}"
    fi
  fi
}

# ── Phase 5: Verification ─────────────────────────────────────────────────

phase5_verify() {
  step "5/5" "Final verification"

  # Gate E: Operator verifies from laptop
  pause_for_operator "From your LAPTOP, verify: curl http://${TS_IP}:8000 should work; curl http://${SERVER_IP}:8000 should NOT"

  # Final validation run
  log "Running final validate_hardening.sh..."
  local final_validate_json
  final_validate_json="$("${SCRIPT_DIR}/validate_hardening.sh" --json 2>/dev/null)" || true
  report_validation_result "Final validation" "${final_validate_json}" \
    "Final validation failed. Resolve validation failures before considering deployment complete."

  # Print summary
  print_deployment_summary
}

# ── Main ────────────────────────────────────────────────────────────────────

main() {
  parse_args "$@"
  collect_inputs
  validate_inputs

  # Show summary before proceeding
  printf '\n'
  log "Deployment configuration:"
  log "  Server:    ${SERVER_IP}"
  log "  Admin:     ${ADMIN_USER}"
  log "  Pubkey:    ${PUBKEY_FILE}"
  log "  Mode:      ${DEPLOY_MODE}"
  log "  Domain:    ${DOMAIN}"
  log "  App scope: ${APP_DOMAIN_MODE}"
  log "  Swap:      ${SWAP_SIZE}"
  confirm "Proceed with deployment?"

  preflight
  phase1_harden
  phase2_gates
  phase3_docker_coolify
  phase4_binding_dns
  phase5_verify
}

main "$@"
