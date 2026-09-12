# V5 Control Architecture: Current State

**Status:** Development only  
**Feature gate:** `SENTINEL_HOST_ENABLED`  
**Last reviewed:** 2026-09-12

## Purpose

The current slice proves that Coolify can manage a host through a secure,
outbound connection without using SSH for each normal request. SSH remains the
bootstrap and recovery path.

## Components

```text
User / Coolify UI
        |
        v
Coolify Laravel control plane
  - product state and authorization
  - Sentinel assignment and credentials
  - Flux PKI and certificate renewal
  - durable server information
        |
        | authenticated HTTP (private network)
        v
Flux
  - direct TLS gRPC listener on a configurable port (default 7443)
  - connected-Sentinel registry
  - command routing, timeouts, and result matching
        ^
        | outbound TLS gRPC stream
        |
Host-native Sentinel (systemd)
  - assignment polling
  - TLS connection and heartbeats
  - typed, capability-gated host commands
  - host information collection
```

Flux does not make product decisions. Coolify selects and authorizes the server
and operation. Flux routes a typed command. Sentinel validates and executes the
host-local operation.

## Implemented flows

### Bootstrap and connection

1. Coolify installs or updates host-native Sentinel over SSH.
2. The installer writes the Sentinel service configuration and the Coolify CA
   trust bundle, then starts the systemd service.
3. Sentinel uses the existing `PUSH_ENDPOINT` and `TOKEN` to poll Coolify for a
   control assignment.
4. Coolify returns a short-lived Flux credential, Flux endpoint, protocol
   version, capabilities, and trust-bundle version.
5. Sentinel opens an outbound TLS gRPC stream to Flux. This works through NAT
   because the managed server starts the connection.
6. Flux registers the live connection and reports connection events to Coolify.

### Connection diagnostics

- The Sentinel page shows connection state, endpoint, transport, protocol,
  trust-bundle version, connection time, and heartbeat time.
- `system.ping.v1` verifies the full Coolify → Flux → Sentinel → Flux → Coolify
  request path.
- UI actions return toast notifications. Connection state remains visible as
  durable page state.

### Server information

- `system.info.v1` returns hostname, OS, kernel, architecture, CPU count, memory,
  root storage, uptime, boot ID, Sentinel version, and container runtime.
- Coolify validates the response and stores it in the existing
  `server_metadata` field.
- The server General page shows the stored information.
- In development, the existing refresh action uses Flux. Outside the feature
  gate, it keeps the current SSH behavior.

## Security model

- Sentinel connects to Flux with TLS and verifies the exact DNS name or IP
  address from its assignment.
- Each Coolify installation owns a private CA valid for 100 years.
- Flux leaf certificates are valid for 90 days and renew when 30 days remain.
- Normal leaf renewal keeps the same CA, so Sentinel does not need a trust
  update.
- Coolify stores CA and leaf private keys encrypted in its database.
- Flux fails closed when production TLS configuration is missing or invalid.
- Production Sentinel rejects plaintext Flux endpoints.
- Commands are typed, versioned, short-lived, capability-gated, and deduplicated
  in memory. Sentinel rejects a command when its type and payload do not match.
- Flux's internal HTTP API uses a bearer token and is for Coolify-to-Flux
  traffic on the private control-plane network. It is not a public API.
- SSH remains available to repair trust, configuration, installation, or a
  failed control channel.

## Certificate operations

| Action | Effect |
| --- | --- |
| Renew certificate | Issues and activates a new Flux leaf certificate under the current CA. It restarts Flux, validates the served certificate, and restores the prior files after a failed check. |
| Repair trust | Reinstalls the current Coolify CA bundle on one server and restarts Sentinel. It does not create a new CA or leaf certificate. |

CA rotation is not implemented. It needs a staged dual-CA bundle, per-server
acknowledgements, an overlap period, and a controlled retirement step.

## Deployment states

### Development

Coolify and Flux run in containers. The testing host runs systemd in its test
container, and host-native Sentinel runs as a systemd service inside it. The
stack uses published `main` images from GHCR. This setup tests the production
process model while keeping local development reproducible.

Developers with KVM access can also start the optional `node-worker` QEMU profile.
It runs Ubuntu, systemd, Podman, the Podman API socket, and host-native Sentinel
on a normal virtual machine. The VM connects to the same development Coolify and
Flux services and is the target for runtime and host-network integration tests.
Coolify stores an explicit server mode. Legacy servers validate Docker and
Docker Compose. `node-worker` and `node-controller-worker` nodes validate Podman,
systemd, and the rootful Podman API socket. Development seeding does not mark a
worker usable before that validation succeeds.

### Existing self-hosted installations

Existing Coolify installations remain on the Docker-based localhost path. They
must not be converted automatically to Podman, WireGuard, or the node
worker stack. Legacy servers keep container Sentinel and SSH. Coolify does not
install host-native Sentinel, issue Flux assignments, or send Flux commands to
legacy servers.

### Fresh v5 installations

The accepted target supports combined, control-plane-only, and worker modes.
Fresh installations can use the node host stack. The full installer,
Podman execution, networking, and placement flows are not implemented in this
slice.

### Coolify Cloud

The target is one or more regional Coolify control planes and Flux endpoints.
Managed servers make outbound TLS connections to their assigned regional Flux.
The current direct-port model avoids dependence on a customer's reverse proxy.
Regional routing, horizontal Flux scaling, shared connection ownership, and
cross-instance command routing are not implemented yet.

## Current boundaries

### Implemented

- host-native Sentinel installation and update over SSH;
- assignment polling with short-lived credentials;
- direct TLS gRPC connection, heartbeats, and connection reporting;
- private CA issuance, Flux leaf issuance, automatic leaf renewal, rollback,
  and SSH trust repair;
- `system.ping.v1` and `system.info.v1` typed commands;
- development-only UI controls and connection state.

### Not implemented

- staged CA rotation;
- production rollout and upgrade policy for host-native Sentinel;
- multi-Flux routing and horizontal scaling;
- durable mutating-command reconciliation;
- the Podman, firewall, DNS, Corrosion, ingress, and builder capabilities that
  will move from the earlier coold design into Sentinel;
- on-demand Sentinel log transport;
- retirement of the existing Sentinel container.

## Next safe step

Add one small, read-only diagnostic command at a time, or implement staged CA
rotation before broad testing. Do not start mutating workload operations until
command reconciliation, idempotency, audit, and restart behavior are specified.
