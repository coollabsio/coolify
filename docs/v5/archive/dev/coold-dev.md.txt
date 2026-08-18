# coold Dev Environment Notes

This file documents the current local v5/coold dev setup in Coolify.

## Roles

- `scripts/dev.sh` is the main developer-facing entrypoint. Use it for normal
  local workflows such as starting/stopping the stack, creating fresh dev state,
  inspecting Corrosion, managing firewall allow rules, and running example
  containers.
- `scripts/coold-vm.sh` is a lower-level Lima VM helper used by `scripts/dev.sh`.
  It exists separately to keep VM lifecycle and guest setup details out of the
  main dev orchestration script. Call it directly only when debugging or
  operating an individual VM, for example `shell`, `status`, `logs-agent`, or
  `delete`.
- Lima VMs act like real deployment servers.
- `coolify init bootstrap` owns host wiring:
  - WireGuard
  - Podman mesh networks
  - Corrosion config/schema/service
  - coold install/service
  - builder install/config
  - default-deny firewall service
- Coolify/Laravel dev owns Flux-specific wiring:
  - starts Flux inside the Coolify container
  - mints dev host JWTs
  - installs `/etc/coolify/host-jwt` into each VM
  - adds a `coold.service` systemd drop-in with `COOLIFY_COOLD_FLUX_URL`

## Main commands

```bash
scripts/dev.sh up
scripts/dev.sh down
scripts/dev.sh clean-vms
scripts/dev.sh list
```

`clean-vms` deletes the Lima VM instances and VM-local state, including disks,
containers, WireGuard keys, Corrosion DB, installed binaries, and firewall state.
It does not delete the Coolify repo.

## coolify helper

```bash
scripts/dev.sh coolify install
scripts/dev.sh coolify path
scripts/dev.sh coolify bootstrap-command
scripts/dev.sh coolify run <args>
```

The helper runs the `coolify` CLI from the Coolify development container. Lima
VMs are addressed by their bridged mDNS names (`<vm>.local`), so the same
addresses are used by bootstrap and by the v5 dev server seeder.

Because Docker Desktop does not reliably pass mDNS multicast into containers,
`scripts/dev.sh up` resolves each `<vm>.local` name on the host and writes the
resolved records into the Coolify container `/etc/hosts` before bootstrapping.

The generated bootstrap command uses the container CLI, the repo-local copy of
the Lima SSH key, and dev WireGuard endpoint overrides, for example:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify /usr/local/bin/coolify init bootstrap \
  --nodes "coold-dev.local,coold-dev-2.local" \
  --ssh-key "/var/www/html/.dev/lima/ssh_key" \
  --ssh-user "coolify" \
  --wg-listen-port-overrides "coold-dev.local=51821,coold-dev-2.local=51822" \
  --wg-endpoint-overrides "coold-dev.local=coold-dev.local:51821,coold-dev-2.local=coold-dev-2.local:51822" \
  --coold-version "nightly" \
  --corrosion-version "v1.0.0" \
  --yes
```

Lima does not allow direct root SSH by default, so dev uses the normal Lima user
with passwordless sudo. `coolify` wraps remote commands in `sudo -n bash -lc`
when the SSH user is not `root`.

## Default dev topology

After `coolify init bootstrap`, defaults are:

| VM | WireGuard IP | WireGuard endpoint | Podman subnet | Gateway |
| --- | --- | --- | --- | --- |
| `coold-dev` | `100.64.0.1` | `coold-dev.local:51821` | `10.210.0.0/24` | `10.210.0.1` |
| `coold-dev-2` | `100.64.0.2` | `coold-dev-2.local:51822` | `10.210.1.0/24` | `10.210.1.1` |

## Checking state

```bash
scripts/dev.sh corrosion check
scripts/dev.sh corrosion containers
scripts/dev.sh corrosion config
scripts/dev.sh corrosion logs 1
scripts/dev.sh corrosion logs 2
```

`corrosion containers` shows both Corrosion `service_endpoints` and rootful /
rootless Podman containers.

## Example nginx containers

```bash
scripts/dev.sh example-nginx up
scripts/dev.sh example-nginx check-dns
scripts/dev.sh example-nginx down
```

The example containers are started with coold DNS:

```bash
--dns <local-mesh-gateway>
--dns-search default.coolify.internal
```

Expected service discovery format:

```text
<container-name>.default.coolify.internal
```

Example:

```text
coolify-example-nginx-2.default.coolify.internal -> 10.210.1.x
```

## Firewall behavior

The mesh firewall is default-deny for inter-container traffic. Host-to-host
WireGuard traffic can work while container-to-container traffic is blocked.

Allow traffic:

```bash
scripts/dev.sh firewall allow 10.210.0.2 10.210.1.2 tcp 80
scripts/dev.sh firewall list
```

Revoke traffic:

```bash
scripts/dev.sh firewall revoke
scripts/dev.sh firewall revoke <rule-id>
```

### Why dev adds allow rules to both hosts

`business traffic` from container A on host 1 to container B on host 2 crosses
forwarding/firewall logic on both sides:

```text
10.210.0.2
  -> source host bridge/firewall
  -> coold-dev wg0
  -> WireGuard
  -> coold-dev-2 wg0
  -> destination host bridge/firewall
  -> 10.210.1.2
```

The default-deny hooks can drop the packet on either the source or destination
host. For the two-node dev setup, `scripts/dev.sh firewall allow` writes the
same allow tuple to every coold VM so the path works reliably.

Production should become topology-aware instead of blindly writing to every
host:

- cross-host traffic: write allow rules to the source and destination hosts
- same-host traffic: write the allow rule only to that host
- larger clusters: do not install unrelated allow rules on unaffected hosts

## Manual connectivity checks

Before allow rule, this should time out:

```bash
scripts/dev.sh shell 1
sudo podman exec coolify-example-nginx wget -T 3 -qO- http://10.210.1.2
```

After allow rule, this should return nginx HTML:

```bash
scripts/dev.sh firewall allow 10.210.0.2 10.210.1.2 tcp 80
scripts/dev.sh shell 1
sudo podman exec coolify-example-nginx wget -T 5 -qO- http://10.210.1.2 | head -n 1
```

Expected:

```html
<!DOCTYPE html>
```
