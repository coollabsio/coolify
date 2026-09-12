# Optional KVM v5 worker design

**Status:** Proposed  
**Date:** 2026-09-12

## Goal

Add an optional Linux virtual machine to the Coolify development environment.
The VM acts as a real v5 worker with systemd, host-native Sentinel, and Podman.
It complements the existing Docker testing host and does not replace it.

The first use case is the `containers.list.v1` flow through Coolify, Flux,
Sentinel, and Podman.

## Chosen approach

Run an Ubuntu cloud image directly with QEMU and KVM acceleration. Use QEMU user
networking and fixed host port forwarding instead of a host bridge or a libvirt
network.

```text
Docker development stack
  Coolify  ───── SSH through host.docker.internal:<ssh-port> ────┐
  Flux     ◄──── TLS gRPC through host port 7443 ────────────────┤
                                                                 │
Host machine                                                     │
  QEMU/KVM v5 worker VM ◄────────────────────────────────────────┘
    systemd
    Sentinel
    Podman
```

The guest reaches the host through QEMU's user-network gateway. Coolify reaches
the guest through an SSH port forwarded on the host. The existing Compose setup
already maps `host.docker.internal` to the Docker host gateway.

### Why not nested Podman

Podman inside the Docker testing host changes cgroups, storage, network
namespaces, and firewall access. It is useful for narrow tests but cannot prove
that v5 works on a normal Linux server.

### Why not libvirt first

Libvirt is useful for larger VM fleets, but it adds a daemon, network setup, and
host-specific permissions. Direct QEMU gives this single optional VM a smaller
setup. We can move to libvirt later if one VM is no longer sufficient.

## Developer interface

Add one script with these commands:

```text
./scripts/v5-worker doctor
./scripts/v5-worker up
./scripts/v5-worker status
./scripts/v5-worker register
./scripts/v5-worker reset
./scripts/v5-worker down
./scripts/v5-worker destroy
```

- `doctor` checks QEMU, KVM access, cloud-image tooling, disk space, and required
  ports. It reports installation guidance but does not modify the host.
- `up` downloads and verifies the pinned cloud image when absent, creates the
  overlay disk and cloud-init seed, and starts the VM.
- `status` reports the process, SSH readiness, forwarded address, and image
  version.
- `register` idempotently adds or updates the development server record in a
  running Coolify container.
- `reset` destroys VM state and creates a clean worker from the cached base
  image.
- `down` requests a clean shutdown and then stops the process after a timeout.
- `destroy` removes generated VM state but keeps the cached base image.

All generated files live under `.dev-v5-worker/` and remain untracked. The
script uses a PID file and refuses to start a second VM for the same worktree.

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

Use configurable host ports with development defaults:

| Setting | Default | Purpose |
| --- | ---: | --- |
| `V5_WORKER_SSH_PORT` | `2223` | Host port forwarded to guest SSH port 22. |
| `V5_WORKER_FLUX_PORT` | `7443` | Host Flux port that Sentinel uses. |
| `V5_WORKER_MEMORY_MB` | `4096` | Guest memory. |
| `V5_WORKER_CPUS` | `2` | Guest virtual CPUs. |
| `V5_WORKER_DISK_GB` | `30` | Overlay disk size. |

The VM does not bind a dashboard or workload port in the first slice. Later
workload tests will add explicit port forwarding or a tap-based network as a
separate design change.

Cloud-init maps `coolify-flux` to QEMU's host-gateway IP. Sentinel uses
`https://coolify-flux:7443`, which matches the existing development certificate
identity. The VM uses the gateway address and host port 8000 for its
`PUSH_ENDPOINT`. Sentinel still performs normal TLS verification for Flux.

## Coolify registration

An idempotent development seeder creates one normal remote server record. It
must not use ID `0`. The `register` command runs this seeder inside the Coolify
container and passes the configured SSH port.

The record uses:

- name `v5-kvm-worker`;
- host `host.docker.internal`;
- the configured forwarded SSH port;
- the existing development testing-host private key;
- the root development team;
- an explicit marker that identifies it as development-only.

Seeding must be idempotent. A normal development start does not run this seeder
and does not create the server. Destroying the VM does not delete an existing
record or user resources.

## Lifecycle integration

The KVM worker stays outside Docker Compose. Compose must not own the QEMU
process.

Jean and local developers can opt in through a separate command. The normal
development command continues to start only the Docker stack. This keeps KVM
optional and prevents shutdown of the Docker stack from killing a VM that a
developer is inspecting.

The script prints the environment values that Coolify and the VM need. A later
small Jean configuration change can add a separate panel command after the
standalone workflow works.

## Failure behavior

- If `/dev/kvm` is absent or inaccessible, `doctor` and `up` stop with a clear
  message. They do not fall back to slow software emulation.
- If a required host port is busy, `up` stops before it creates a QEMU process.
- If cloud-init or SSH does not become ready before the timeout, `up` stops the
  VM and preserves logs for inspection.
- If the base image checksum does not match, the script deletes the bad download
  and stops.
- `down` and `destroy` verify the recorded PID before they send a signal.

## Verification

The first implementation is complete when these checks pass:

1. The normal Docker development stack still starts without KVM.
2. `doctor` gives a clear result on hosts with and without KVM access.
3. `up` starts a fresh VM and SSH becomes ready.
4. The guest runs systemd and Podman.
5. Coolify can reach the VM through the forwarded SSH port.
6. Coolify installs host-native Sentinel on the VM.
7. Sentinel connects to Flux with TLS and reports heartbeats.
8. A VM reboot restores Sentinel and the Flux connection.
9. `reset` creates a clean worker.
10. `down` and `destroy` leave no QEMU process behind.

## Deferred work

- `containers.list.v1` implementation;
- workload port routing;
- WireGuard;
- Corrosion;
- firewall and nftables tests;
- multi-VM support;
- macOS hosts without KVM;
- continuous-integration runners with hardware virtualization.
