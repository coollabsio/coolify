# Optional KVM v5 worker design

**Status:** Implemented
**Date:** 2026-09-12

## Goal

Add an optional Linux virtual machine to the Coolify development environment.
The VM acts as a real v5 worker with systemd, host-native Sentinel, and Podman.
It complements the existing Docker testing host and does not replace it.

The first use case is the `containers.list.v1` flow through Coolify, Flux,
Sentinel, and Podman.

## Chosen approach

Extend Coolify's existing libvirt-based development VM manager with a dedicated
Ubuntu v5 worker profile. The existing manager already creates cloud-image VMs,
configures a NAT network that the Coolify container can reach, assigns fixed IP
addresses, and seeds normal remote-server records.

```text
Docker development stack
  Coolify  ───── SSH through libvirt network ────────────────────┐
  Flux     ◄──── TLS gRPC through host port 7443 ────────────────┤
                                                                 │
Host machine and libvirt NAT network                             │
  QEMU/KVM v5 worker VM ◄────────────────────────────────────────┘
    systemd
    Sentinel
    Podman
```

The worker uses the existing fixed-address libvirt network. Coolify reaches the
guest directly on that network. The guest reaches the published Coolify and Flux
ports through the libvirt gateway.

### Why not nested Podman

Podman inside the Docker testing host changes cgroups, storage, network
namespaces, and firewall access. It is useful for narrow tests but cannot prove
that v5 works on a normal Linux server.

### Why reuse libvirt

Coolify already has tested libvirt host setup, VM creation, cloud-init, fixed IP
allocation, and database seeding. Reusing it avoids a second VM manager and keeps
the v5 worker compatible with the existing root and non-root test profiles.

## Developer interface

Use the existing Artisan interface:

```text
php artisan dev:qemu v5-worker
```

The command uses the existing `dev:qemu` reset behavior. It prepares libvirt,
recreates the selected VM, waits for SSH, and seeds the server record. Running
`php artisan dev:qemu` without an argument shows the profile selector.

## Guest configuration

Cloud-init creates a dedicated development user and installs:

- OpenSSH server;
- Podman;
- required container networking packages;
- curl and basic diagnostic tools.

The VM boots with systemd. Sentinel is not baked into the image. Coolify installs
Sentinel through the existing SSH installation action so development tests the
same installation path as a remote server.

The cloud-init configuration installs the public key that matches Coolify's
existing development testing-host key. It does not create or copy a private
key. The private key remains in Coolify's development data.

## Network contract

Use the existing libvirt network with one new fixed address:

| Setting | Value | Purpose |
| --- | ---: | --- |
| Worker address | `192.168.122.50` | Coolify SSH target. |
| Gateway address | `192.168.122.1` | Guest path to Coolify and Flux. |
| SSH port | `22` | Direct SSH on the libvirt network. |
| Flux port | `7443` | Published development Flux listener. |

The VM does not bind a dashboard or workload port in the first slice. Later
workload tests will add explicit port forwarding or a tap-based network as a
separate design change.

Cloud-init maps `coolify-flux` to QEMU's host-gateway IP. Sentinel uses
`https://coolify-flux:7443`, which matches the existing development certificate
identity. The VM uses the gateway address and host port 8000 for its
`PUSH_ENDPOINT`. Sentinel still performs normal TLS verification for Flux.

## Coolify registration

The existing idempotent development QEMU seeder creates one normal remote server
record. It must not use ID `0`.

The record uses:

- name `v5-kvm-worker`;
- host `192.168.122.50`;
- SSH port `22`;
- the existing development testing-host private key;
- the root development team;
- an explicit marker that identifies it as development-only.

Seeding must be idempotent. A normal development start does not run this seeder
and does not create the server. Destroying the VM does not delete an existing
record or user resources.

## Lifecycle integration

The KVM worker stays outside Docker Compose. Libvirt owns the QEMU process.

Jean and local developers can opt in through a separate command. The normal
development command continues to start only the Docker stack. This keeps KVM
optional and prevents shutdown of the Docker stack from killing a VM that a
developer is inspecting.

The script prints the environment values that Coolify and the VM need. A later
small Jean configuration change can add a separate panel command after the
standalone workflow works.

## Failure behavior

- If KVM or a required libvirt tool is unavailable, the existing host setup
  stops with a clear error.
- If cloud-init or SSH does not become ready before the timeout, `up` stops the
  VM and preserves logs for inspection.
- If the base image checksum does not match, the script deletes the bad download
  and stops.

## Verification

The first implementation is complete when these checks pass:

1. The normal Docker development stack still starts without KVM.
2. The profile appears in the existing `dev:qemu` selector.
3. `php artisan dev:qemu v5-worker` starts a fresh VM and SSH becomes ready.
4. The guest runs systemd and Podman.
5. Coolify can reach the VM through the forwarded SSH port.
6. Coolify installs host-native Sentinel on the VM.
7. Sentinel connects to Flux with TLS and reports heartbeats.
8. A VM reboot restores Sentinel and the Flux connection.
9. Running the command again creates a clean worker.

## Deferred work

- `containers.list.v1` implementation;
- workload port routing;
- WireGuard;
- Corrosion;
- firewall and nftables tests;
- multi-VM support;
- macOS hosts without KVM;
- continuous-integration runners with hardware virtualization.
