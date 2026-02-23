#!/usr/bin/env bash
# lib/coolify-common.sh — Shared utilities for deploy.sh and setup.sh.
# Source this file; do not execute it directly.
# Requires: set -Eeuo pipefail in the caller.

[[ "${BASH_SOURCE[0]}" != "${0}" ]] \
  || { printf 'Source this file, do not execute it.\n' >&2; exit 1; }
[[ -z "${_COOLIFY_COMMON_LOADED:-}" ]] || return 0
_COOLIFY_COMMON_LOADED=1

# ── Regex patterns ──────────────────────────────────────────────────────────

IPV4_RE='^([0-9]{1,3}\.){3}[0-9]{1,3}$'
LINUX_USER_RE='^[a-z_][a-z0-9_-]*$'
FQDN_RE='^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$'
SWAP_RE='^[0-9]+[GM]$'

# ── Helpers ─────────────────────────────────────────────────────────────────

log()  { printf '[%s] %s\n' "$(date -Iseconds)" "$*"; }
warn() { log "WARN: $*"; }
die()  { log "FATAL: $*" >&2; exit 1; }
step() { printf '\n\033[1;36m[%s] %s\033[0m\n' "$1" "$2"; }
pass() { printf '  \033[1;32mPASS\033[0m %s\n' "$*"; }
fail() { printf '  \033[1;31mFAIL\033[0m %s\n' "$*"; }

is_true() {
  case "${1,,}" in
    1|true|yes|y|on) return 0 ;;
    *) return 1 ;;
  esac
}

confirm() {
  if is_true "${AUTO_YES}"; then return 0; fi
  local msg="${1:-Continue?}"
  printf '\n%s [y/N] ' "${msg}"
  read -r ans
  case "${ans,,}" in
    y|yes) return 0 ;;
    *) die "Aborted by user." ;;
  esac
}

# ── Input helpers ───────────────────────────────────────────────────────────

prompt_value() {
  local var_name="$1" prompt="$2" default="${3:-}" regex="${4:-}"
  local val
  # When --yes is set and a default exists, accept it without prompting
  if is_true "${AUTO_YES}" && [[ -n "${default}" ]]; then
    eval "${var_name}=\${default}"
    return 0
  fi
  printf '%s' "${prompt}"
  [[ -n "${default}" ]] && printf ' [%s]' "${default}"
  printf ': '
  read -r val
  val="${val:-$default}"
  if [[ -n "${regex}" ]] && ! [[ "${val}" =~ ${regex} ]]; then
    die "Invalid input for ${var_name}: '${val}' does not match ${regex}"
  fi
  eval "${var_name}=\${val}"
}

prompt_secret() {
  local var_name="$1" prompt="$2"
  local val
  printf '%s: ' "${prompt}"
  read -rs val
  printf '\n'
  [[ -n "${val}" ]] || die "${var_name} cannot be empty."
  eval "${var_name}=\${val}"
}

prompt_choice() {
  local var_name="$1" prompt="$2" default="$3"
  shift 3
  local options=("$@")
  # When --yes is set, accept the default without prompting
  if is_true "${AUTO_YES}"; then
    eval "${var_name}=\${default}"
    return 0
  fi
  printf '%s [%s] (%s): ' "${prompt}" "${default}" "$(IFS=/; echo "${options[*]}")"
  read -r val
  val="${val:-$default}"
  local valid=false
  for opt in "${options[@]}"; do
    [[ "${val}" == "${opt}" ]] && valid=true
  done
  ${valid} || die "Invalid choice for ${var_name}: '${val}'. Options: ${options[*]}"
  eval "${var_name}=\${val}"
}

# ── Cloudflare API ─────────────────────────────────────────────────────────

cf_api() {
  local method="$1" endpoint="$2" body="${3:-}"
  local url="https://api.cloudflare.com/client/v4${endpoint}"
  local args=(-s -X "${method}" -H "Authorization: Bearer ${CF_API_TOKEN}" -H "Content-Type: application/json")
  [[ -n "${body}" ]] && args+=(-d "${body}")
  curl "${args[@]}" "${url}"
}

cf_verify_token() {
  # Use zones endpoint rather than /user/tokens/verify — the latter requires
  # User:User Tokens:Read which is not part of our required token permissions.
  local resp
  resp="$(cf_api GET /zones?per_page=1)"
  local status
  status="$(printf '%s' "${resp}" | jq -r '.success // false')"
  [[ "${status}" == "true" ]] || die "Cloudflare API token verification failed: $(printf '%s' "${resp}" | jq -r '.errors[0].message // "unknown"')"
  log "Cloudflare API token verified."
}

cf_get_zone_id() {
  # If --cf-zone was specified, use it directly
  if [[ -n "${CF_ZONE}" ]]; then
    local resp
    resp="$(cf_api GET "/zones?name=${CF_ZONE}&status=active")"
    CF_ZONE_ID="$(printf '%s' "${resp}" | jq -r '.result[0].id // empty')"
    [[ -n "${CF_ZONE_ID}" ]] || die "Cloudflare zone not found for '${CF_ZONE}'. Check --cf-zone value."
    CF_ZONE_NAME="${CF_ZONE}"
    log "Cloudflare zone ID: ${CF_ZONE_ID} (${CF_ZONE_NAME})"
    return 0
  fi

  # Auto-detect zone by trying progressively shorter suffixes of DOMAIN.
  # This correctly handles multi-part TLDs (e.g. .com.au, .co.uk) where
  # stripping only the first label would give a non-existent zone.
  local candidate="${DOMAIN}"
  while [[ "${candidate}" == *.* ]]; do
    local resp
    resp="$(cf_api GET "/zones?name=${candidate}&status=active")"
    CF_ZONE_ID="$(printf '%s' "${resp}" | jq -r '.result[0].id // empty')"
    if [[ -n "${CF_ZONE_ID}" ]]; then
      CF_ZONE_NAME="${candidate}"
      log "Cloudflare zone ID: ${CF_ZONE_ID} (${CF_ZONE_NAME})"
      return 0
    fi
    candidate="${candidate#*.}"  # strip leftmost label and retry
  done
  die "Cloudflare zone not found for any suffix of '${DOMAIN}'. Check domain or use --cf-zone."
}

cf_get_account_id() {
  local resp
  resp="$(cf_api GET /accounts)"
  CF_ACCOUNT_ID="$(printf '%s' "${resp}" | jq -r '.result[0].id // empty')"
  [[ -n "${CF_ACCOUNT_ID}" ]] || die "No Cloudflare account found."
  log "Cloudflare account ID: ${CF_ACCOUNT_ID}"
}

cf_expect_success() {
  local action="$1" resp="$2"
  local success
  success="$(printf '%s' "${resp}" | jq -r '.success // false' 2>/dev/null || echo "false")"
  if [[ "${success}" != "true" ]]; then
    local err
    err="$(printf '%s' "${resp}" | jq -r '[.errors[]?.message] | join("; ")' 2>/dev/null || true)"
    [[ -n "${err}" && "${err}" != "null" ]] || err="unknown"
    die "${action} failed: ${err}"
  fi
}

cf_upsert_a_record() {
  local name="$1" ip="$2" proxied="${3:-true}"
  local existing
  existing="$(cf_api GET "/zones/${CF_ZONE_ID}/dns_records?type=A&name=${name}")"
  local record_id
  record_id="$(printf '%s' "${existing}" | jq -r '.result[0].id // empty')"
  local body
  body="$(jq -n --arg name "${name}" --arg ip "${ip}" --argjson proxied "${proxied}" \
    '{type:"A",name:$name,content:$ip,proxied:$proxied,ttl:1}')"
  local resp

  if [[ -n "${record_id}" ]]; then
    resp="$(cf_api PUT "/zones/${CF_ZONE_ID}/dns_records/${record_id}" "${body}")"
    cf_expect_success "Cloudflare A record update (${name})" "${resp}"
    log "Updated A record: ${name} → ${ip} (proxied=${proxied})"
  else
    resp="$(cf_api POST "/zones/${CF_ZONE_ID}/dns_records" "${body}")"
    cf_expect_success "Cloudflare A record create (${name})" "${resp}"
    log "Created A record: ${name} → ${ip} (proxied=${proxied})"
  fi
}

cf_create_tunnel() {
  local stop_fn="${1:-}"   # optional: name of function to call to stop cloudflared
  local tunnel_name="${DOMAIN%%.*}-coolify"

  # Delete any existing tunnel with the same name (idempotent re-run support).
  # Stop cloudflared first so it releases active connections — the CF API rejects DELETE for
  # tunnels with active connections, and the name stays reserved even after a failed delete.
  local existing_id
  existing_id="$(cf_api GET "/accounts/${CF_ACCOUNT_ID}/cfd_tunnel?name=${tunnel_name}&is_deleted=false" \
    | jq -r '.result[0].id // empty')"
  if [[ -n "${existing_id}" ]]; then
    log "Stopping cloudflared on server to release tunnel connections before delete..."
    [[ -n "${stop_fn}" ]] && "${stop_fn}"
    sleep 3  # Allow connections to close
    log "Deleting stale tunnel ${tunnel_name} (${existing_id}) before recreating..."
    cf_api DELETE "/accounts/${CF_ACCOUNT_ID}/cfd_tunnel/${existing_id}" >/dev/null \
      || warn "Could not delete stale tunnel ${existing_id}; proceeding anyway."
    sleep 2  # Allow CF to release the name
  fi

  TUNNEL_SECRET="$(openssl rand -base64 32)"
  local body
  body="$(jq -n --arg name "${tunnel_name}" --arg secret "${TUNNEL_SECRET}" \
    '{name:$name,tunnel_secret:$secret,config_src:"local"}')"
  local resp
  resp="$(cf_api POST "/accounts/${CF_ACCOUNT_ID}/cfd_tunnel" "${body}")"
  TUNNEL_ID="$(printf '%s' "${resp}" | jq -r '.result.id // empty')"
  [[ -n "${TUNNEL_ID}" ]] || die "Failed to create Cloudflare Tunnel: $(printf '%s' "${resp}" | jq -r '.errors[0].message // "unknown"')"
  log "Created tunnel: ${tunnel_name} (${TUNNEL_ID})"
}

cf_upsert_cname() {
  local name="$1" target="$2"
  local existing
  existing="$(cf_api GET "/zones/${CF_ZONE_ID}/dns_records?type=CNAME&name=${name}")"
  local record_id
  record_id="$(printf '%s' "${existing}" | jq -r '.result[0].id // empty')"
  local body
  body="$(jq -n --arg name "${name}" --arg target "${target}" \
    '{type:"CNAME",name:$name,content:$target,proxied:true,ttl:1}')"
  local resp

  if [[ -n "${record_id}" ]]; then
    resp="$(cf_api PUT "/zones/${CF_ZONE_ID}/dns_records/${record_id}" "${body}")"
    cf_expect_success "Cloudflare CNAME update (${name})" "${resp}"
    log "Updated CNAME: ${name} → ${target}"
  else
    resp="$(cf_api POST "/zones/${CF_ZONE_ID}/dns_records" "${body}")"
    cf_expect_success "Cloudflare CNAME create (${name})" "${resp}"
    log "Created CNAME: ${name} → ${target}"
  fi
}

# ── Shared deployment helpers ────────────────────────────────────────────────

# report_validation_result — Parse and report validate_hardening.sh JSON output.
# Caller captures JSON (via SSH or locally) and passes it in as the second argument.
# Usage: report_validation_result "Gate C" "${validate_json}" "Gate C failed. ..."
report_validation_result() {
  local label="$1" validate_json="$2" die_msg="$3"
  local fail_count
  fail_count="$(printf '%s' "${validate_json}" | jq -r '.fail // -1' 2>/dev/null || echo "-1")"
  if [[ "${fail_count}" == "0" ]]; then
    pass "${label}: validate_hardening.sh — 0 failures"
  else
    fail "${label}: validate_hardening.sh reported ${fail_count} failures"
    printf '%s\n' "${validate_json}" | jq '.checks[] | select(.status=="FAIL")' 2>/dev/null || true
    die "${die_msg}"
  fi
}

# collect_common_inputs — Prompt for inputs shared by both deploy.sh and setup.sh.
# Each script calls this then adds its own script-specific prompts.
collect_common_inputs() {
  [[ -n "${SERVER_IP}" ]]   || prompt_value  SERVER_IP "Server public IP" "" "${IPV4_RE}"
  [[ -n "${ADMIN_USER}" ]]  || prompt_value  ADMIN_USER "Admin username" "coolifyadmin" "${LINUX_USER_RE}"
  [[ -n "${PUBKEY_FILE}" ]] || prompt_value  PUBKEY_FILE "SSH public key file" "${HOME}/.ssh/id_ed25519.pub"
  [[ -n "${TAILSCALE_AUTH_KEY}" ]] || prompt_value TAILSCALE_AUTH_KEY "Tailscale auth key (tskey-auth-...)" ""
  [[ -n "${DEPLOY_MODE}" ]] || prompt_choice DEPLOY_MODE "Deployment mode" "tunnel" "tunnel" "standard"
  [[ -n "${DOMAIN}" ]]      || prompt_value  DOMAIN "Domain name (FQDN)" "" "${FQDN_RE}"
  [[ -n "${CF_API_TOKEN}" ]] || prompt_secret CF_API_TOKEN "Cloudflare API token"
  # CF_ZONE intentionally left as-is (derived from domain when empty; --cf-zone overrides)
  [[ -n "${SWAP_SIZE}" ]]   || SWAP_SIZE="2G"
  # App subdomain scope: where Coolify auto-assigns app URLs.
  #   apex → appname.CF_ZONE     e.g. appname.example.com      (default — Free Universal SSL)
  #   vps  → appname.DOMAIN      e.g. appname.vps.example.com  (server-scoped; needs ACM/Enterprise for proxied SSL)
  if [[ -z "${APP_DOMAIN_MODE}" ]]; then
    printf '  App subdomain scope:\n'
    printf '    apex → appname.ZONE_APEX                (default — works with Cloudflare Free SSL)\n'
    printf '    vps  → appname.%s  (scoped to this server; requires paid ACM or Enterprise for proxied SSL)\n' "${DOMAIN:-DOMAIN}"
    prompt_choice APP_DOMAIN_MODE "App subdomain scope" "apex" "apex" "vps"
  fi
}

# resolve_app_domain — Set APP_DOMAIN from APP_DOMAIN_MODE after CF_ZONE_NAME is known.
# Call this after cf_get_zone_id.
resolve_app_domain() {
  if [[ "${APP_DOMAIN_MODE}" == "apex" ]]; then
    APP_DOMAIN="${CF_ZONE_NAME}"
  else
    APP_DOMAIN="${DOMAIN}"
    if [[ "${DOMAIN}" != "${CF_ZONE_NAME}" ]]; then
      warn "vps mode: DOMAIN (${DOMAIN}) is a subdomain of zone ${CF_ZONE_NAME}."
      warn "Apps at appname.${DOMAIN} are two levels deep and NOT covered by Cloudflare Free Universal SSL."
      warn "Use --app-domain-mode apex for free proxied SSL, or provision ACM / CF for SaaS manually."
    fi
  fi
  log "App subdomain scope: ${APP_DOMAIN_MODE} — new apps at appname.${APP_DOMAIN}"
}

# print_deployment_summary — Print completion banner and next-steps block.
# Uses globals: SERVER_IP, TS_IP, ADMIN_USER, DEPLOY_MODE, DOMAIN, CF_ZONE_NAME, APP_DOMAIN, TUNNEL_ID
print_deployment_summary() {
  printf '\n'
  printf '┌─────────────────────────────────────────────────────────────┐\n'
  printf '│                    DEPLOYMENT COMPLETE                      │\n'
  printf '├─────────────────────────────────────────────────────────────┤\n'
  printf '│  Server Public IP : %-40s│\n' "${SERVER_IP}"
  printf '│  Tailscale IP     : %-40s│\n' "${TS_IP}"
  printf '│  Admin User       : %-40s│\n' "${ADMIN_USER}"
  printf '│  Deploy Mode      : %-40s│\n' "${DEPLOY_MODE}"
  printf '│  Domain           : %-40s│\n' "${DOMAIN}"
  printf '│  Dashboard URL    : %-40s│\n' "http://${TS_IP}:8000"
  printf '│  SSH Access       : ssh %-36s│\n' "${ADMIN_USER}@${TS_IP}"
  printf '├─────────────────────────────────────────────────────────────┤\n'
  if [[ "${DEPLOY_MODE}" == "standard" ]]; then
    printf '│  DNS              : A %-38s│\n' "${DOMAIN} → ${SERVER_IP}"
    printf '│  Wildcard DNS     : A *.%-36s│\n' "${APP_DOMAIN}"
    [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]] \
      && printf '│                   + A *.%-36s│\n' "${CF_ZONE_NAME}"
  else
    printf '│  DNS              : CNAME %-34s│\n' "${DOMAIN}"
    printf '│  Wildcard DNS     : CNAME *.%-32s│\n' "${APP_DOMAIN}"
    [[ "${APP_DOMAIN}" != "${CF_ZONE_NAME}" ]] \
      && printf '│                   + CNAME *.%-32s│\n' "${CF_ZONE_NAME}"
    printf '│  Tunnel ID        : %-40s│\n' "${TUNNEL_ID}"
    printf '│  WebSocket (Soketi): ws.%-36s│\n' "${DOMAIN} → tunnel"
    printf '│  Terminal         : terminal.%-31s│\n' "${DOMAIN} → tunnel"
  fi
  printf '└─────────────────────────────────────────────────────────────┘\n'
  printf '\n'
  log "Next steps:"
  log "  1. Open http://${TS_IP}:8000 and create your Coolify admin account."
  log ""
  log "  2. Cloudflare SSL mode (one-time):"
  log "       Cloudflare dashboard > your zone > SSL/TLS > Overview > set to 'Full'"
  log "       (not Full Strict — Coolify uses self-signed certs internally)"
  log ""
  log "  3. Start the proxy: Coolify UI > Servers > localhost > Proxy > Start Proxy"
  log "       (required for app subdomains to route through Traefik)"
  log ""
  log "  4. Wildcard Domain is already set to http://${APP_DOMAIN} (done automatically)."
  log "       New apps will get  http://appname.${APP_DOMAIN}"
  log "       If an app already has a sslip.io URL: App > Settings > Domains > update it."
  log ""
  log "  5. For each app deployment in Coolify:"
  log "       Use http:// domain (not https://) — Cloudflare proxy adds TLS."
  log "       Example:  http://myapp.${APP_DOMAIN}"
  log ""
  log "  6. Deploy your first app — it gets a subdomain + Cloudflare SSL automatically."
}
