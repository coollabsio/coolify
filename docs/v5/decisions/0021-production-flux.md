# Decision 0021: Run Flux as a service in the Coolify container

**Status: Accepted**

**Date: 2026-11-12**

## Context

Nodes need Flux: Sentinel on each Node keeps a TLS gRPC connection to Flux, and
Coolify sends every Node command through the Flux internal API. Today Flux
exists only in the development stack (`docker-compose.node-dev.yml`):

- The production compose file, `install.sh`, and `upgrade.sh` have no Flux
  service, no port 7443, and no `FLUX_*` keys.
- Coolify reads the Flux settings from environment variables
  (`config/constants.php`, `flux.*`): the signing key pair, the issuer, the
  internal token, the internal URL, and the public URL. The development values
  are fixed strings in the compose file.
- Without `FLUX_PUBLIC_URL`, `ResolveFluxPublicUrl` builds the URL from
  `app.url`, which is `http://localhost` on most installs, and rejects it.
- Coolify already has a private Flux CA and issues, renews, and writes the
  Flux server certificate (`EnsureFluxCertificateAuthority`,
  `IssueFluxCertificate`, `MaterializeFluxCertificate`). Sentinel trusts that
  CA through the trust bundle.
- Flux reads its certificate only when it starts
  (`sentinel/crates/flux/src/tls.rs`, `load_server_tls`). A renewal on disk
  does not reach a running Flux.
- `nodesEnabled()` requires a development instance for these reasons.

Flux is a Rust gRPC server with one long-lived connection for each Node, so it
must be its own long-running process. The Coolify container already runs two
such servers as s6 services: Reverb and the terminal WebSocket server.

We compared a Flux container that Coolify starts on its host over SSH, like
the proxy. It needs SSH to the Coolify host, a separate image pin and upgrade
path, and an internal API on the Docker network.

## Decision

### Flux is an s6 service in the Coolify container

- The Coolify image copies the `flux` binary from a pinned Flux image at build
  time. Flux ships with each Coolify release and has no separate upgrade path.
- An s6 service `flux` runs next to Reverb and the terminal server. It runs
  Flux only when Nodes are on (decision 0015) or a Node exists; otherwise it
  stays down.
- The internal API listens on `127.0.0.1:7080` inside the container. Only the
  gRPC port 7443 is published (`docker-compose.prod.yml`); `upgrade.sh` brings
  the new compose file to existing installs.

### Coolify generates and stores the Flux secrets

- On the first start, Coolify generates the Ed25519 signing key pair, the key
  ID, and the internal token, and stores them encrypted in the database. The
  issuer is `APP_ID`.
- Coolify reads these values from the database. The `FLUX_*` environment
  variables stay as overrides, for development and for Coolify Cloud.
- The s6 run script gets the values Flux needs (public signing key, key ID,
  issuer, internal token, certificate paths) from an artisan command and
  starts Flux with them. Flux never gets the private signing key.
- A key rotation uses the existing previous-key fields of Flux
  (`FLUX_PREVIOUS_SIGNING_KEY_ID`, `FLUX_PREVIOUS_SIGNING_PUBLIC_KEY`).

### The Flux URL is an instance setting

- A new instance setting holds the host and port that Nodes use to reach Flux.
  The default is the instance domain, or else the public IPv4 address, with
  port 7443 (the same order as `base_url()`).
- The existing private CA issues the Flux server certificate for that host. A
  change of the setting issues a new certificate and restarts Flux.
- Flux needs a direct TLS connection on its port. It does not run behind the
  proxy.

### Certificate renewal restarts Flux

- After a renewal or a CA rotation writes new files, Coolify restarts the s6
  service (`s6-svc -r`). Sentinels reconnect within seconds.
- A hot reload of the certificate inside Flux can replace the restart later.

### Reachability check

- Port 7443 must be open from every Node to the Coolify host. Coolify cannot
  open cloud firewalls.
- Node onboarding checks the TLS connection from the Node to the Flux URL
  before it installs Sentinel, and names the host and port in the error.
- The Nodes setting shows the Flux URL and the port to open.

### The development gate goes away

- `nodesEnabled()` no longer requires a development instance. The instance
  setting of decision 0015 stays off by default, and Nodes keep the Beta label.

## Consequences

- No SSH from Coolify to its own host for Flux, and no second image to pin
  and upgrade.
- Port 7443 is published on every install. Nothing listens on it while Nodes
  are off. When another program of the host listens on port 7443 and
  `FLUX_PORT` is not set, `upgrade.sh` (also run by `install.sh`) writes the
  next free port as `FLUX_PORT` to `/data/coolify/source/.env` and prints a
  notice, so the Coolify container still starts. The default `Flux port` is
  then that port.
- A restart of the Coolify container (an upgrade) and each certificate renewal
  drop the Node connections for a few seconds. Workloads keep running.

## Not decided here

- More than one Flux, regional Flux, and Coolify Cloud (roadmap P3). Cloud
  keeps its own deployment and the environment overrides.
- A hot reload of the Flux certificate.
- Flux on a host other than the Coolify host.
