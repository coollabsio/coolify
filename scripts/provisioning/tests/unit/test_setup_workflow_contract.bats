#!/usr/bin/env bats
# Tier 0: Contract tests for setup.sh workflow structure

load '../helpers'

SETUP_SCRIPT="${PROJECT_ROOT}/setup.sh"
DEPLOY_MATRIX="${PROJECT_ROOT}/docs/deploy_setup_functionality_test_matrix.md"

@test "setup: preflight phase marker exists" {
  grep -Fq 'step "0/5" "Pre-flight checks"' "${SETUP_SCRIPT}"
}

@test "setup: phase1 harden marker exists" {
  grep -Fq 'phase1_harden()' "${SETUP_SCRIPT}"
  grep -Fq 'step "1/5" "Harden server"' "${SETUP_SCRIPT}"
}

@test "setup: gate A requires operator laptop verification" {
  grep -Fq 'Gate A: Operator verifies SSH from laptop' "${SETUP_SCRIPT}"
}

@test "setup: gate B verifies admin user home and ssh directory" {
  grep -Fq 'Gate B: Admin user' "${SETUP_SCRIPT}"
  grep -Fq '.ssh not found' "${SETUP_SCRIPT}"
}

@test "setup: gate C runs validate_hardening.sh json" {
  grep -Fq "Gate C: Running validate_hardening.sh..." "${SETUP_SCRIPT}"
  grep -Fq 'validate_hardening.sh" --json' "${SETUP_SCRIPT}"
}

@test "setup: gate D validates service active and managed rules" {
  grep -Fq "verify_docker_user_gate_local()" "${SETUP_SCRIPT}"
  grep -Fq "systemctl is-active --quiet docker-user-hardening.service" "${SETUP_SCRIPT}"
  grep -Fq 'verify_docker_user_gate_local "Gate D"' "${SETUP_SCRIPT}"
  grep -Fq "coolify-hardening" "${SETUP_SCRIPT}"
}

@test "setup: phase4 binding+dns marker exists" {
  grep -Fq 'phase4_binding_dns()' "${SETUP_SCRIPT}"
  grep -Fq 'step "4/5" "Configure dashboard binding & DNS"' "${SETUP_SCRIPT}"
}

@test "setup: binding failure is fatal" {
  grep -Fq 'configure_coolify_binding.sh" --tailscale-ip' "${SETUP_SCRIPT}"
  grep -Fq 'die "configure_coolify_binding.sh failed. Fix binding errors before continuing."' "${SETUP_SCRIPT}"
}

@test "setup: PUSHER env supports mode switch and expanded domain" {
  grep -Fq 'PUSHER_HOST=ws.${DOMAIN}' "${SETUP_SCRIPT}"
  grep -Fq 'PUSHER env vars cleared for standard mode' "${SETUP_SCRIPT}"
  grep -Fq "sed '/^PUSHER_HOST=/d; /^PUSHER_PORT=/d; /^PUSHER_SCHEME=/d'" "${SETUP_SCRIPT}"
}

@test "setup: tunnel terminal ingress uses dashboard path (not terminal subdomain)" {
  grep -Fq 'path: /terminal/ws' "${SETUP_SCRIPT}"
  grep -Fq 'service: http://localhost:6002' "${SETUP_SCRIPT}"
  ! grep -Fq 'hostname: terminal.${DOMAIN}' "${SETUP_SCRIPT}"
}

@test "setup: gate E requires operator laptop verification" {
  grep -Fq 'Gate E: Operator verifies from laptop' "${SETUP_SCRIPT}"
}

@test "setup: final validation is executed" {
  grep -Fq "Running final validate_hardening.sh..." "${SETUP_SCRIPT}"
}

@test "setup: matrix includes all SET contract ids" {
  grep -Fq "SET-01" "${DEPLOY_MATRIX}"
  grep -Fq "SET-09" "${DEPLOY_MATRIX}"
}
