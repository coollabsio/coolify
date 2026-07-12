#!/usr/bin/env bash
#
# Railpack end-to-end deploy smoke test against the local dev stack.
#
# Walks a curated set of railpack-* example apps from
# DevelopmentRailpackExamplesSeeder, triggers a deploy via the Coolify API,
# waits for the deployment queue to finish, then exec()s into the resulting
# container and checks that COOLIFY_*, SOURCE_COMMIT, and any RAILPACK_*
# build inputs landed correctly. Optionally curls the FQDN.
#
# Requires:
#   - Dev stack running: spin up (or docker compose -f docker-compose.dev.yml up -d)
#   - Seeder run:        php artisan db:seed --class=DevelopmentRailpackExamplesSeeder
#   - Personal token:    PersonalAccessTokenSeeder run (creates Bearer 'root')
#   - jq, curl available on host
#
# Usage:
#   scripts/railpack-smoke.sh                          # default subset
#   scripts/railpack-smoke.sh --app railpack-laravel   # single app
#   scripts/railpack-smoke.sh --all                    # every seeded railpack-* app
#   scripts/railpack-smoke.sh --timeout 900            # build wait per app, seconds
#   scripts/railpack-smoke.sh --no-curl                # skip FQDN curl
#   scripts/railpack-smoke.sh --extra-env KEY=VALUE    # build+runtime env (alias of --both-env)
#   scripts/railpack-smoke.sh --build-env KEY=VALUE    # buildtime-only env (must reach build, NOT runtime)
#   scripts/railpack-smoke.sh --runtime-env KEY=VALUE  # runtime-only env (must reach runtime, NOT build)
#   scripts/railpack-smoke.sh --both-env KEY=VALUE     # buildtime+runtime env
#
set -euo pipefail

API_BASE="${COOLIFY_API_BASE:-http://localhost:8000/api/v1}"
TOKEN="${COOLIFY_API_TOKEN:-root}"
TIMEOUT="${SMOKE_TIMEOUT:-600}"
DO_CURL=1
BUILD_ENVS=()
RUNTIME_ENVS=()
BOTH_ENVS=()
APPS=()

DEFAULT_APPS=(
    railpack-expressjs
    railpack-nestjs
    railpack-nextjs-ssr
    railpack-vite-static
    railpack-astro-static
    railpack-python-flask
    railpack-go-gin
    railpack-rust
    railpack-symfony
    railpack-bun
)

while (( $# > 0 )); do
    case "$1" in
        --app) APPS+=("$2"); shift 2 ;;
        --all) APPS=(__ALL__); shift ;;
        --timeout) TIMEOUT="$2"; shift 2 ;;
        --no-curl) DO_CURL=0; shift ;;
        --extra-env|--both-env) BOTH_ENVS+=("$2"); shift 2 ;;
        --build-env) BUILD_ENVS+=("$2"); shift 2 ;;
        --runtime-env) RUNTIME_ENVS+=("$2"); shift 2 ;;
        --base) API_BASE="$2"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

if ! command -v jq >/dev/null; then
    echo "jq required" >&2; exit 2
fi
if ! command -v docker >/dev/null; then
    echo "docker required" >&2; exit 2
fi

curl_api() {
    local method="$1"; shift
    local path="$1"; shift
    curl -fsS -X "$method" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        "${API_BASE}${path}" \
        "$@"
}

if (( ${#APPS[@]} == 0 )); then
    APPS=("${DEFAULT_APPS[@]}")
fi

if [[ "${APPS[0]}" == "__ALL__" ]]; then
    mapfile -t APPS < <(curl_api GET /applications | jq -r '.[].uuid' | grep '^railpack-' || true)
fi

log()  { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
fail() { printf '\033[31m[FAIL]\033[0m %s: %s\n' "$1" "$2"; FAILED+=("$1: $2"); }
pass() { printf '\033[32m[ OK ]\033[0m %s: %s\n' "$1" "$2"; }

upsert_env() {
    local app_uuid="$1" key="$2" value="$3" buildtime="$4" runtime="$5" existing
    existing=$(curl_api GET "/applications/${app_uuid}/envs" | jq -r --arg k "$key" '.[] | select(.key==$k) | .uuid' | head -1)
    local payload
    payload=$(jq -nc --arg k "$key" --arg v "$value" --argjson b "$buildtime" --argjson r "$runtime" \
        '{key:$k, value:$v, is_buildtime:$b, is_runtime:$r, is_preview:false}')
    if [[ -n "$existing" ]]; then
        curl_api PATCH "/applications/${app_uuid}/envs" --data "$payload" >/dev/null
        log "  env ${key} updated (buildtime=${buildtime} runtime=${runtime})"
    else
        curl_api POST "/applications/${app_uuid}/envs" --data "$payload" >/dev/null
        log "  env ${key} created (buildtime=${buildtime} runtime=${runtime})"
    fi
}

ensure_envs() {
    local app_uuid="$1" kv key value
    for kv in "${BUILD_ENVS[@]:-}"; do
        [[ -z "$kv" ]] && continue
        key="${kv%%=*}"; value="${kv#*=}"
        upsert_env "$app_uuid" "$key" "$value" true false
    done
    for kv in "${RUNTIME_ENVS[@]:-}"; do
        [[ -z "$kv" ]] && continue
        key="${kv%%=*}"; value="${kv#*=}"
        upsert_env "$app_uuid" "$key" "$value" false true
    done
    for kv in "${BOTH_ENVS[@]:-}"; do
        [[ -z "$kv" ]] && continue
        key="${kv%%=*}"; value="${kv#*=}"
        upsert_env "$app_uuid" "$key" "$value" true true
    done
}

trigger_deploy() {
    local app_uuid="$1"
    curl_api POST "/applications/${app_uuid}/start?force=true&instant_deploy=true" \
        | jq -r '.deployment_uuid // empty'
}

wait_for_deploy() {
    local dep_uuid="$1" deadline="$2" status
    while (( $(date +%s) < deadline )); do
        status=$(curl_api GET "/deployments/${dep_uuid}" | jq -r '.status // "unknown"')
        case "$status" in
            finished) echo finished; return 0 ;;
            failed|cancelled) echo "$status"; return 1 ;;
            queued|in_progress) sleep 5 ;;
            *) sleep 5 ;;
        esac
    done
    echo timeout; return 1
}

container_for_app() {
    local app_uuid="$1"
    docker ps --filter "name=^${app_uuid}-" --format '{{.Names}}' | head -1
}

assert_envs_present() {
    local container="$1" app_uuid="$2"
    local env_dump
    env_dump=$(docker exec "$container" env 2>/dev/null || true)

    local missing=()
    for required in COOLIFY_FQDN COOLIFY_URL COOLIFY_BRANCH COOLIFY_RESOURCE_UUID COOLIFY_CONTAINER_NAME SOURCE_COMMIT; do
        if ! grep -q "^${required}=" <<<"$env_dump"; then
            missing+=("$required")
        fi
    done

    local resource_uuid
    resource_uuid=$(grep '^COOLIFY_RESOURCE_UUID=' <<<"$env_dump" | cut -d= -f2- || true)
    if [[ "$resource_uuid" != "$app_uuid" ]]; then
        missing+=("COOLIFY_RESOURCE_UUID-mismatch(got=${resource_uuid})")
    fi

    if (( ${#missing[@]} == 0 )); then
        pass "$app_uuid" "runtime envs present (${resource_uuid})"
        return 0
    fi
    fail "$app_uuid" "missing/incorrect envs: ${missing[*]}"
    return 1
}

deploy_logs_text() {
    local dep_uuid="$1"
    curl_api GET "/deployments/${dep_uuid}" | jq -r '(.logs | fromjson? // []) | .[].output' 2>/dev/null
}

assert_runtime_only_envs() {
    local container="$1" app_uuid="$2"
    [[ ${#RUNTIME_ENVS[@]} -eq 0 ]] && return 0
    local env_dump key value actual
    env_dump=$(docker exec "$container" env 2>/dev/null || true)
    for kv in "${RUNTIME_ENVS[@]}"; do
        key="${kv%%=*}"; value="${kv#*=}"
        if ! grep -q "^${key}=" <<<"$env_dump"; then
            fail "$app_uuid" "runtime-only env ${key} missing at runtime"
            return 1
        fi
        actual=$(grep "^${key}=" <<<"$env_dump" | head -1 | cut -d= -f2-)
        if [[ "$actual" != "$value" ]]; then
            fail "$app_uuid" "runtime env ${key} value mismatch (got=${actual} want=${value})"
            return 1
        fi
    done
    pass "$app_uuid" "runtime-only envs present at runtime (${#RUNTIME_ENVS[@]} key(s))"
}

assert_build_only_envs() {
    local container="$1" app_uuid="$2" dep_uuid="$3"
    [[ ${#BUILD_ENVS[@]} -eq 0 ]] && return 0
    local env_dump logs key
    env_dump=$(docker exec "$container" env 2>/dev/null || true)
    logs=$(deploy_logs_text "$dep_uuid")

    for kv in "${BUILD_ENVS[@]}"; do
        key="${kv%%=*}"
        # Reach build: railpack passes buildtime envs as docker buildx --secret id=KEY
        if ! grep -q -- "--secret id=${key}" <<<"$logs"; then
            fail "$app_uuid" "build-only env ${key} not seen as --secret in deploy logs"
            return 1
        fi
        # Must NOT leak to runtime container
        if grep -q "^${key}=" <<<"$env_dump"; then
            fail "$app_uuid" "build-only env ${key} LEAKED to runtime container"
            return 1
        fi
    done
    pass "$app_uuid" "build-only envs in build secret + absent at runtime (${#BUILD_ENVS[@]} key(s))"
}

assert_both_envs() {
    local container="$1" app_uuid="$2" dep_uuid="$3"
    [[ ${#BOTH_ENVS[@]} -eq 0 ]] && return 0
    local env_dump logs key
    env_dump=$(docker exec "$container" env 2>/dev/null || true)
    logs=$(deploy_logs_text "$dep_uuid")
    for kv in "${BOTH_ENVS[@]}"; do
        key="${kv%%=*}"
        if [[ "$key" =~ ^RAILPACK_ ]]; then
            # RAILPACK_* are buildtime-only by railpack convention; skip runtime check
            grep -q -- "--secret id=${key}" <<<"$logs" \
                || { fail "$app_uuid" "${key} not seen in build secrets"; return 1; }
            continue
        fi
        grep -q "^${key}=" <<<"$env_dump" \
            || { fail "$app_uuid" "both-env ${key} missing at runtime"; return 1; }
    done
    pass "$app_uuid" "both-envs reached runtime (${#BOTH_ENVS[@]} key(s))"
}

assert_fqdn_responds() {
    local app_uuid="$1"
    local fqdn
    fqdn=$(curl_api GET "/applications/${app_uuid}" | jq -r '.fqdn // empty')
    [[ -z "$fqdn" ]] && return 0
    local code
    code=$(curl -ksSL -o /dev/null -w '%{http_code}' --max-time 10 "$fqdn" || echo "000")
    case "$code" in
        2*|3*|4*) pass "$app_uuid" "fqdn ${fqdn} -> ${code}" ;;
        *)     fail "$app_uuid" "fqdn ${fqdn} -> ${code}" ;;
    esac
}

run_one() {
    local app_uuid="$1"
    log "==> ${app_uuid}"

    if ! curl_api GET "/applications/${app_uuid}" >/dev/null 2>&1; then
        fail "$app_uuid" "application not found via API (run seeder?)"
        return
    fi

    ensure_envs "$app_uuid"

    local dep
    dep=$(trigger_deploy "$app_uuid")
    if [[ -z "$dep" ]]; then
        fail "$app_uuid" "no deployment_uuid returned"
        return
    fi
    log "  deploy queued: ${dep}"

    local deadline=$(( $(date +%s) + TIMEOUT ))
    local result
    result=$(wait_for_deploy "$dep" "$deadline") || {
        fail "$app_uuid" "deploy ${result}"
        return
    }
    pass "$app_uuid" "deploy ${result}"

    sleep 2
    local container
    container=$(container_for_app "$app_uuid")
    if [[ -z "$container" ]]; then
        fail "$app_uuid" "no running container matching name=^${app_uuid}-"
        return
    fi
    pass "$app_uuid" "container ${container} running"

    assert_envs_present "$container" "$app_uuid" || true
    assert_runtime_only_envs "$container" "$app_uuid" || true
    assert_build_only_envs "$container" "$app_uuid" "$dep" || true
    assert_both_envs "$container" "$app_uuid" "$dep" || true

    if (( DO_CURL )); then
        assert_fqdn_responds "$app_uuid" || true
    fi
}

FAILED=()
for app in "${APPS[@]}"; do
    run_one "$app"
done

echo
echo "=== summary ==="
if (( ${#FAILED[@]} == 0 )); then
    echo "all apps passed"
    exit 0
fi
printf '%s failure(s):\n' "${#FAILED[@]}"
printf '  - %s\n' "${FAILED[@]}"
exit 1
