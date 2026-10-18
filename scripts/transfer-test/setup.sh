#!/usr/bin/env bash
# Prepare the transfer test kit: start the target instance (B) next to the source (A), and let them reach
# each other and A's VMs. Safe to run again, for example after a host reboot. Linux with KVM only.
#
# Usage: scripts/transfer-test/setup.sh    (then scripts/transfer-test/reset.sh)
source "$(dirname "$0")/lib.sh"

[[ -c /dev/kvm ]] || die 'The kit needs KVM VMs (Linux). See scripts/transfer-test/README.md.'
running "$A" || die "Start the source instance first: ./scripts/dev start"

echo "== 1. target instance $TARGET (side by side, testing-host as its localhost)"
# On a new instance, its server seeding can run before the migrations. That does not matter: reset.sh seeds B again.
COOLIFY_DEV_INSTANCE="$TARGET" COOLIFY_DEV_SIDE_BY_SIDE=1 COOLIFY_DEV_SERVER_BACKEND=testing-host ./scripts/dev start ||
  echo '  (the seeding of the new instance failed; reset.sh seeds both databases again)'
running "$B" || die "The target instance $B does not run."
target_values

echo "== 2. firewall: B may reach the VM network of A (10.221.$A_SLOT.0/24)"
subnet="$(docker network inspect "$B" --format '{{(index .IPAM.Config 0).Subnet}}')"
rule=(LIBVIRT_FWI -s "$subnet" -d "10.221.$A_SLOT.0/24" -o "cdevbr$A_SLOT" -j ACCEPT)
if "${PRIVILEGED[@]}" iptables -C "${rule[@]}" 2>/dev/null; then
  echo "  rule exists"
else
  "${PRIVILEGED[@]}" iptables -I "${rule[0]}" 1 "${rule[@]:1}" && echo "  rule added for $subnet"
fi

echo "== 3. A and B reach each other's app port"
bind="$(grep -E '^DEV_BIND_ADDRESS=' .env 2>/dev/null | tail -1 | cut -d= -f2-)"
if [[ "${bind:-0.0.0.0}" == 127.0.0.1 ]]; then
  # The ports listen on 127.0.0.1 only; containers reach the host through the docker0 gateway.
  gateway="$(docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}')"
  for port in "$A_PORT" "$B_PORT"; do
    name="coolify-transfer-test-forward-$port"
    if running "$name"; then
      echo "  $name runs"
    else
      docker rm -f "$name" >/dev/null 2>&1 || true
      docker run -d --name "$name" --network host --restart unless-stopped alpine/socat \
        "tcp-listen:$port,bind=$gateway,fork,reuseaddr" "tcp:127.0.0.1:$port" >/dev/null && echo "  $name started ($gateway:$port -> 127.0.0.1:$port)"
    fi
  done
else
  echo "  not needed: the ports listen on ${bind:-0.0.0.0}"
fi

echo "Done. A: $A_URL  B: $B_URL"
echo "Next: scripts/transfer-test/reset.sh"
