# V5 Control Architecture: Current State

**Status:** Development only  
**Feature gate:** `SENTINEL_HOST_ENABLED`, on a development instance only  
**Last reviewed:** 2026-11-12

## Purpose

Coolify manages Nodes through a secure, outbound connection without SSH for
each normal request. SSH remains the bootstrap and recovery path. On a cluster
of Nodes, Coolify builds, deploys, moves, and serves cluster applications and
databases. `nodesEnabled()` in `bootstrap/helpers/shared.php` is the only gate.
It needs `SENTINEL_HOST_ENABLED=true` and a development instance, because Flux
ships only in the development stack.

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
  - WireGuard and dedicated nftables reconciliation
  - Podman workloads, volumes, secret files, and builds
  - Caddy ingress and the ACME certificate issuer
        |
        v
Node cluster data plane
  - full-mesh WireGuard on stable private addresses
  - Corrosion replication over WireGuard: endpoints, routes, certificates
  - private workload DNS on each Node WireGuard address
  - Caddy on ingress Nodes, ports 80 and 443
  - builder Nodes and one cluster image registry
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

### Container inventory

- `container.list.v1` reads the complete Podman container list from a Node.
- Sentinel normalizes runtime data and returns it through the typed control
  protocol. It does not change containers.
- Coolify validates the complete response before it changes stored data, then
  reconciles the snapshot into `node_containers` in one transaction.
- Standard Coolify labels link observed containers to an assigned workload and
  immutable revision. Containers are shown as managed, external, or
  unrecognized.
- The Node page shows the stored inventory and has a manual refresh action.
- Coolify schedules `RefreshConnectedNodesJob` every minute on one scheduler.
  The job selects usable Nodes with a Flux heartbeat from the last two minutes
  and queues one unique inventory job per Node with a delay of 0 to 59 seconds.
  The schedule uses Node and connection state directly. It does not use the
  `SENTINEL_HOST_ENABLED` development variable.
- Each complete inventory stores its observation time. The Node page compares
  the newest workload revision with managed containers from that snapshot and
  shows `Running`, `Stopped`, `Outdated`, or `Missing`. It shows `Unknown`
  before the first snapshot and `Stale` when the last snapshot is more than
  three minutes old.

### Durable Node operations

- Coolify stores each future mutating Node command in `node_operations` before
  dispatch. The record contains the Node, workload revision, command type,
  request, requester, idempotency key, attempts, timestamps, result, and error.
- Operation states are queued, dispatched, running, verifying, succeeded,
  failed, timed out, uncertain, and cancelled. Invalid state changes are
  rejected.
- An idempotency key is unique per Node. Reuse returns the existing operation
  only when its complete request identity matches.
- Successful operations are retained for 30 days. Failed, timed-out, and
  cancelled operations are retained for 90 days. Active and uncertain
  operations are not removed by retention cleanup.
- Sentinel stores accepted command requests and final protobuf results in a
  separate SQLite journal before and after execution. Matching completed
  commands replay their original result after a restart. A command left running
  by a restart is reported as interrupted and is not executed again.
- Sentinel retains completed command results for seven days, with a limit of
  100,000 completed records. Active records do not count toward this limit and
  are not removed automatically.

### Minimal workload deployment

- `workload.deploy.v1` deploys one Podman container from an immutable workload
  revision. The first contract supports an image, command arguments,
  environment values, published ports, labels, and a restart policy.
- Coolify creates the durable operation before it sends the command. It uses
  the operation UUID as the command ID and stores only the revision identity
  and configuration hash in the operation request. It does not copy environment
  values into the operation journal.
- Sentinel validates all fields and invokes Podman without a shell. The stable
  container name and `--replace` make a newer revision replace the prior main
  container for that workload.
- A successful deployment triggers a full container inventory refresh. The
  standard labels then connect the observed runtime container to its workload
  and revision.
- After Sentinel completes the command, the operation enters `verifying`.
  Coolify refreshes the complete inventory and confirms that a managed
  container for the requested revision and image is running. Only then does
  the operation become `succeeded`. A missing, stopped, or mismatched container
  makes the operation fail with its verification result stored in the journal.
- Each deployment attempt has its own idempotency key and durable command UUID.
  Coolify permits another deployment of the same revision after the prior
  operation reaches a final state. While an operation is active, another click
  reuses that operation and does not queue concurrent work.
- A lost transport result changes the operation to `uncertain`. The Node UI can
  recover it. Coolify first refreshes inventory and marks the operation as
  successful when the requested revision is already running. Otherwise, it
  sends the same operation UUID again. Sentinel then replays its stored result
  instead of running the command twice.

### Operation recovery policy

`RecoverStaleNodeOperationsJob` runs every five minutes. It recovers
operations that stay `queued`, `dispatched`, `running`, or `uncertain` for
longer than `constants.node.operation_stale_after_minutes` (default 20). The
Node page also shows durable operation states and provides a **Recover**
button for an `uncertain` operation.

Recovery reuses the existing operation UUID. It never creates a new command for
the same attempt. A redeploy or restart is successful only when Sentinel
acknowledged the operation UUID. A running container is not proof, and Coolify
does not compare Node clock times with its own. Without proof, recovery sends
the same operation UUID again, at most three dispatch attempts in total.
Sentinel's command journal then returns the stored result, or runs the command
once if it never ran. Start, stop, and remove are still confirmed from the
container state, because that state alone proves them. A stale move releases the
target placement that the move attached, unless the target became ready.

### Workload lifecycle

- `workload.lifecycle.v1` starts, stops, restarts, or removes the stable Podman
  container for a workload.
- Coolify creates a durable operation before each action. Flux routes the typed
  action, and Sentinel invokes Podman without a shell.
- Start and restart converge when the requested revision and image are running.
  Stop converges when the managed container exists but is not running. Remove
  converges when no managed container for the workload remains.
- An uncertain recovery refreshes inventory before it sends a command. If the
  requested state already exists, Coolify completes the operation without a
  replay. Otherwise, it reuses the original operation UUID.
- Coolify serializes deployment and lifecycle operations for each workload so
  two mutating commands cannot run against the same container at once.
- Coolify polls fresh inventory for up to 15 seconds so transitional Podman
  states such as `stopping` do not produce a false result.
- The Sentinel systemd unit uses `KillMode=process`. Restarting or upgrading
  Sentinel must not kill the Podman `conmon` processes that supervise running
  workloads.

### Node volumes

[Decision 0007](../decisions/0007-node-volumes.md) defines the model.

- A `NodeVolume` belongs to one cluster application and lives on one Node.
  `node_id` is the authority for the location of the data. It is null until
  the first deployment, which pins the volume to its Node. The Podman volume
  name is `coolify-vol-<uuid>`.
- The revision stores the mounts in `configuration.volumes`, so a changed mount
  makes a new revision and a new configuration hash. `workload.deploy.v1` sends
  the mounts only to a Node that reports `volume.mounts.v1`. A deployment or a
  start of a volume on another Node fails.
- `container.list.v1` also reports the Coolify volumes of the Node. Coolify
  stores the names in `nodes.volume_inventory`, shows each volume as ready,
  missing, or not created, and lists orphan volumes on the server page.
- A removed volume is detached first and its data stays. The user deletes the
  data with `volume.delete.v1`. Deleting an application deletes its volumes
  only when the user keeps "Delete volumes" checked.
- A move of an application with volumes is break-before-make: `volume.serve.v1`
  on the source, a warm `volume.receive.v1` pass, a stop of the source, a final
  pass, and the switch of `node_id` in one transaction. The source copy stays as
  a `retained` `NodeVolumeCopy` for 7 days. A failure before the target
  deployment rolls back to the source. The move job checkpoints each step in the
  operation result, and one cleanup method undoes a failed move from the job,
  its `failed()` handler, and stale operation recovery.

### Node cluster network

- A team-owned Node cluster is the authority for Node membership, one private
  IPv4 CIDR, stable Node addresses, WireGuard peer intent, firewall policy, and
  the desired network revision. A Node can belong to zero or one cluster.
- Coolify allocates an unused `/24` from `10.240.0.0/12`. The first usable
  address is reserved for cluster infrastructure, and Node addresses start at
  the second usable address. A cluster is limited to 100 Nodes.
- The first network topology is a full WireGuard mesh. Sentinel creates private
  keys on each Node and returns only public keys and observed interface state.
- Sentinel stages each WireGuard and firewall change, arms a systemd rollback,
  checks the applied state and Flux access, and cancels rollback only after the
  checks pass. Automatic rollback waits for the restore command to finish.
  Successful activation, rollback, and SSH repair restore the route-only DNS
  settings after the WireGuard link is recreated.
- Sentinel owns only the `inet coolify_cluster` nftables table. It does not
  flush or replace UFW, firewalld, or user-owned nftables rules.

### Replicated endpoint discovery

- Sentinel installs the pinned Corrosion release as a separate host-native
  systemd service. Gossip and the HTTP API bind only to the Node WireGuard
  address. A stable non-zero Corrosion cluster ID separates each Coolify Node
  cluster.
- Sentinel publishes the endpoints of its own Node every 15 seconds from local
  Podman state, with a 120-second expiry. It replaces only rows whose owner
  matches the local Node WireGuard address.
- Coolify provides identity, not liveness. Each managed container carries the
  workload DNS name in the `coolify.dns_name` label. Coolify sends the Node DNS
  name as `node_dns_name` with the Corrosion configuration. Discovery keeps
  working while Coolify is down.
- A changed workload DNS name applies after the next deployment, because
  container labels do not change in place.
- Corrosion replicates workload identity, namespace, owning Node, reachable
  endpoint address, state, health, update time, and expiry. It cannot change
  authoritative membership or network configuration.
- Each Node runs a small authoritative DNS service on its WireGuard address for
  `<workload>.<namespace>.coolify.internal`. It returns only running,
  healthy-or-unknown, non-expired IPv4 endpoints. It returns an authoritative
  empty response for AAAA questions so dual-stack clients do not wait for a
  timeout. Systemd-resolved routes only the `coolify.internal` zone to this
  service.

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
- Commands are typed, versioned, short-lived, capability-gated, and journaled.
  Sentinel rejects a command when its type and payload do not match.
- Flux's internal HTTP API uses a bearer token and is for Coolify-to-Flux
  traffic on the private control-plane network. It is not a public API.
- SSH remains available to repair trust, configuration, installation, or a
  failed control channel.

## Certificate operations

| Action | Effect |
| --- | --- |
| Renew certificate | Issues and activates a new Flux leaf certificate under the current CA. It restarts Flux, validates the served certificate, and restores the prior files after a failed check. |
| Repair trust | Reinstalls the current Coolify CA bundle on one server and restarts Sentinel. During a CA rotation the current bundle holds both CAs. It does not create a new CA or leaf certificate. |
| Rotate CA | Replaces the installation CA in stages without downtime. Instance admins run it from **Settings → Node trust** or with `php artisan flux:rotate-ca`. |

### CA rotation

Coolify stores each rotation in `flux_ca_rotations` and each Node's
acknowledged trust bundle version in `nodes.flux_trust_bundle_version`. Every
step re-reads that state, so a failed or interrupted step can be retried.

1. **Start** creates a pending CA and publishes trust bundle N+1 with the old
   and new CA (`distributing`).
2. Coolify sends the bundle to each connected Node over the authenticated Flux
   channel as `trust.bundle.update.v1`. Sentinel validates it (PEM CA
   certificates only, 64 KiB and 8 certificates at most, not expired, version
   strictly newer or the identical bundle), writes the bundle and then its
   version file atomically next to the current files, keeps the replaced pair
   as `.previous`, and acknowledges the installed version. Trust anchors never
   travel through the assignment polling channel. Offline Nodes receive the
   bundle when they reconnect (the Flux `connected` event reports their
   installed version) and from a five-minute scheduled retry.
3. **Switch** requires every usable Node to acknowledge N+1. It issues a Flux
   leaf from the new CA through the renewal path (restart, served-certificate
   check, file rollback), then makes the new CA active (`switched`). Sentinel
   reads the bundle from disk on every connection, so it reconnects without a
   restart.
4. **Retire** publishes bundle N+2 with the new CA only (`retiring`). After
   every usable Node acknowledges it, the old CA is marked `retired` and its
   private key is erased (`completed`). Leaf renewal always uses the active CA.

Before the switch, **cancel** discards the new CA, erases its key, and
publishes N+2 with the old CA only (`cancelling` → `cancelled`).

The switch and the final retire or cancel step can be forced when some Nodes
have not acknowledged the bundle. Those Nodes need **Repair trust** over SSH.
Sentinels without the `trust.bundle.update.v1` capability also need Repair
trust (or a Sentinel upgrade, which installs the current bundle).

Sentinel reports its installed bundle version in the assignment request and
in the Flux hello. Coolify returns that version in the assignment while the
bundle still trusts the CA of the served Flux leaf, so the Node can connect and
receive the next bundle. Otherwise Coolify returns the current version, and
Sentinel refuses to connect until trust is repaired.

## Deployment states

### Development

Coolify and Flux run in containers. The testing host runs systemd in its test
container, and host-native Sentinel runs as a systemd service inside it. The
stack uses published `main` images from GHCR. This setup tests the production
process model while keeping local development reproducible.

Developers with KVM access can also start the optional two-Node QEMU profile.
Each worker runs Ubuntu, systemd, Podman, the Podman API socket, WireGuard,
nftables, host-native Sentinel, Corrosion, and private discovery DNS on a normal
virtual machine. Both VMs connect to the same development Coolify and Flux
services and provide a complete cross-Node integration topology.
Legacy hosts are stored in `servers`, and nodes are stored in the separate
`nodes` table. Legacy servers validate Docker and Docker Compose. Nodes validate
Podman, systemd, and the rootful Podman API socket. Development seeding does not
mark a worker usable before that validation succeeds.

### Existing self-hosted installations

Existing Coolify installations remain on the Docker-based localhost path. They
must not be converted automatically to Podman, WireGuard, or the node
worker stack. Legacy servers keep container Sentinel and SSH. Coolify does not
install host-native Sentinel, issue Flux assignments, or send Flux commands to
legacy servers. Legacy servers remain a supported product path after nodes are
released; they are not a temporary compatibility mode.

### Fresh v5 installations

The [0002 amendment](../decisions/0002-separate-legacy-upgrades-from-v5-installs.md#amendment-2026-11-11)
defines the v5.0 scope. Coolify installs in Docker Compose with the current
installer (`node-controller`). Nodes join over SSH from the Coolify UI
(`node-worker`). The combined mode, with Coolify and a Node on one host, is
postponed. The code has no separate installer mode for Nodes.

### Coolify Cloud

The target is one or more regional Coolify control planes and Flux endpoints.
Managed servers make outbound TLS connections to their assigned regional Flux.
The current direct-port model avoids dependence on a customer's reverse proxy.
Regional routing, horizontal Flux scaling, shared connection ownership, and
cross-instance command routing are not implemented yet.

## Current boundaries

### Implemented

Control channel:

- host-native Sentinel installation and update over SSH;
- assignment polling with short-lived credentials;
- direct TLS gRPC connection, heartbeats, and connection reporting;
- private CA issuance, Flux leaf issuance, automatic leaf renewal, rollback,
  SSH trust repair, and staged CA rotation;
- typed, capability-gated commands, durable Coolify operations, and the
  Sentinel command journal with result replay;
- Sentinel, Corrosion, discovery DNS, and container logs through Flux
  ([decision 0003](../decisions/0003-transport-sentinel-logs-on-demand.md));
- a team notification when a Node loses its Flux heartbeat, and again when it
  recovers (`NotifyNodeReachability`).

Cluster network:

- team-owned clusters, CIDR and stable address allocation, and a 100-Node
  limit;
- full-mesh WireGuard and scoped nftables with staged activation and systemd
  rollback;
- Corrosion endpoint replication and private workload DNS;
- the two-QEMU-Node development topology.

Cluster applications:

- deployment from an image, with start, stop, restart, remove, and recovery of
  uncertain operations;
- container health checks: a deployment waits until the new container is
  healthy (`DeployNodeWorkloadJob`);
- runtime variables, and variables delivered as secret files
  ([decision 0008](../decisions/0008-node-workload-secrets.md));
- private image pulls and pushes to an external registry with team registry
  credentials (`RegistryCredential`);
- builds on builder Nodes into the cluster registry, with the Dockerfile,
  Railpack, and Static build packs
  ([decision 0006](../decisions/0006-node-image-builds.md));
- public and private Git repositories, push webhooks, and the deploy API
  ([decision 0011](../decisions/0011-node-git-sources.md));
- volumes pinned to one Node, and moves with volumes
  ([decision 0007](../decisions/0007-node-volumes.md));
- volume backups to S3 and restore
  ([decision 0009](../decisions/0009-node-volume-backups.md));
- databases of 8 engines as typed cluster applications with one volume
  ([decision 0010](../decisions/0010-node-databases.md));
- moves of stateless applications without downtime;
- manual Sentinel upgrade with an SSH rollback, for one Node or for all Nodes
  of a team one at a time (`UpgradeSentinel`).

Ingress ([decision 0005](../decisions/0005-node-ingress.md)):

- Caddy on ingress Nodes, with routes and endpoints from Corrosion;
- the v4 domain settings: a port and a path per domain, www and HTTPS
  redirects, the redirect type, and search indexing (`ingress.routes.v2`);
- HTTPS with ACME HTTP-01 certificates, stored in Corrosion and served by
  every ingress Node. The
  [0005 amendment](../decisions/0005-node-ingress.md#amendment-2026-11-12)
  describes how it works.

### Not implemented

- Nodes outside a development instance, and the instance setting of
  [decision 0015](../decisions/0015-node-release-criteria.md) that replaces
  `SENTINEL_HOST_ENABLED`;
- a Sentinel release with every capability that Coolify grants. `upstream.json`
  pins `sentinel-host` 1.1.5. Sentinel v1.1.5 and v1.1.6 do not have private
  Git builds, volume backup and restore, registry credentials, external pushes,
  or `image.load.v1`;
- **Upgrade cluster** as [decision 0013](../decisions/0013-host-sentinel-upgrades.md)
  defines it. The code upgrades all Nodes of a team, not of one cluster, and
  waits 60 seconds for a reconnect, not 5 minutes;
- build-time secrets ([decision 0012](../decisions/0012-node-build-secrets.md));
- database dump backups with `database.dump.v1`
  ([decision 0010](../decisions/0010-node-databases.md));
- recovery of an application with volumes from an offline Node
  ([decision 0014](../decisions/0014-node-placement-and-failure.md)). A restore
  to another Node still copies the other volumes from the source
  (`MoveNodeWorkloadJob::copyVolumes()`);
- revision rollback, exec and terminal, TCP and UDP port publishing, DNS
  records, and placement by CPU architecture (decisions
  [0017](../decisions/0017-node-revision-rollback.md),
  [0018](../decisions/0018-node-exec-and-terminal.md),
  [0016](../decisions/0016-node-port-publishing.md),
  [0019](../decisions/0019-node-dns-records.md), and
  [0020](../decisions/0020-node-cpu-architecture.md));
- DNS-01 certificates and wildcard domains;
- preview deployments, a Dockerfile without Git, and deployment without
  downtime on one Node;
- Docker Compose and one-click services on Nodes;
- automatic placement and automatic failover;
- multi-Flux routing and horizontal scaling.

## Next safe step

Release Sentinel with the capabilities that Coolify grants in
`IssueFluxCredential`, then pin that release in `upstream.json`. A Node that
installs the pinned 1.1.5 today cannot build a private repository or back up a
volume. The roadmap tracks this as items B13 and B1.
