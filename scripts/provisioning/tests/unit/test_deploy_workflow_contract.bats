#!/usr/bin/env bats
# Tier 0: Contract tests for deploy.sh workflow structure

load '../helpers'

DEPLOY_SCRIPT="${PROJECT_ROOT}/deploy.sh"
DEPLOY_MATRIX="${PROJECT_ROOT}/docs/deploy_setup_functionality_test_matrix.md"

@test "deploy: preflight phase marker exists" {
  grep -Fq 'step "0/5" "Pre-flight checks"' "${DEPLOY_SCRIPT}"
}

@test "deploy: phase1 upload+harden marker exists" {
  grep -Fq 'phase1_upload_harden()' "${DEPLOY_SCRIPT}"
  grep -Fq 'step "1/5" "Upload scripts & harden server"' "${DEPLOY_SCRIPT}"
}

@test "deploy: hardening invocation uses env-file and tailscale install" {
  grep -Fq '/root/bootstrap_hardening.sh --env-file /root/deploy.env --install-tailscale --force' "${DEPLOY_SCRIPT}"
}

@test "deploy: gate A checks admin SSH on tailscale" {
  grep -Fq 'Gate A: Testing SSH admin@' "${DEPLOY_SCRIPT}"
}

@test "deploy: gate B verifies admin identity" {
  grep -Fq 'Gate B: whoami=' "${DEPLOY_SCRIPT}"
}

@test "deploy: gate C runs validate_hardening.sh json" {
  grep -Fq "Gate C: Running validate_hardening.sh..." "${DEPLOY_SCRIPT}"
  grep -Fq "validate_hardening.sh --json" "${DEPLOY_SCRIPT}"
}

@test "deploy: gate D validates service active and managed rules" {
  grep -Fq "verify_docker_user_gate_remote()" "${DEPLOY_SCRIPT}"
  grep -Fq "systemctl is-active --quiet docker-user-hardening.service" "${DEPLOY_SCRIPT}"
  grep -Fq 'verify_docker_user_gate_remote "Gate D"' "${DEPLOY_SCRIPT}"
  grep -Fq "coolify-hardening" "${DEPLOY_SCRIPT}"
}

@test "deploy: phase4 binding+dns marker exists" {
  grep -Fq 'phase4_binding_dns()' "${DEPLOY_SCRIPT}"
  grep -Fq 'step "4/5" "Configure dashboard binding & DNS"' "${DEPLOY_SCRIPT}"
}

@test "deploy: binding failure is fatal" {
  grep -Fq 'configure_coolify_binding.sh --tailscale-ip' "${DEPLOY_SCRIPT}"
  grep -Fq 'die "configure_coolify_binding.sh failed. Fix binding errors before continuing."' "${DEPLOY_SCRIPT}"
}

@test "deploy: PUSHER env supports mode switch and expanded domain" {
  grep -Fq 'mode="${DEPLOY_MODE}"' "${DEPLOY_SCRIPT}"
  grep -Fq 'PUSHER_HOST=ws.${DOMAIN}' "${DEPLOY_SCRIPT}"
  grep -Fq 'PUSHER env vars cleared for standard mode' "${DEPLOY_SCRIPT}"
  ! grep -Fq "<<'INNER'" "${DEPLOY_SCRIPT}"
}

@test "deploy: gate E fails when exposure checks do not pass" {
  grep -Fq "Gate E: Checking dashboard accessibility..." "${DEPLOY_SCRIPT}"
  grep -Fq "Gate E failed: dashboard not reachable via Tailscale" "${DEPLOY_SCRIPT}"
  grep -Fq "Gate E failed: dashboard reachable on public IP" "${DEPLOY_SCRIPT}"
}

@test "deploy: final validation is executed" {
  grep -Fq "Running final validate_hardening.sh..." "${DEPLOY_SCRIPT}"
}

@test "deploy: matrix includes all DEP contract ids" {
  grep -Fq "DEP-01" "${DEPLOY_MATRIX}"
  grep -Fq "DEP-10" "${DEPLOY_MATRIX}"
}
