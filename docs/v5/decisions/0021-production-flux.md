# Decision 0021: Run Flux on the Coolify host, managed by Coolify

**Status: Proposed**

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

Coolify already runs and upgrades containers on its own host (server `0`):
the proxy and the Sentinel container of v4.

## Decision

### Coolify runs the Flux container on its host

- Coolify starts Flux as a container `coolify-flux` on server `0` over SSH,
  the same way it manages the proxy and the v4 Sentinel container. It is not
  part of `docker-compose.prod.yml`.
- Coolify starts Flux when an instance admin turns on Nodes (decision 0015),
  and stops it when Nodes are off and no Node exists. A default install opens
  no new port.
- The container joins the `coolify` Docker network, so Coolify reaches the
  internal API at `http://coolify-flux:7080`. Only port 7443 is published.
- The Flux image is pinned in `upstream.json` and `versions.json` with a
  version and digest, like `sentinel-host`. When Coolify starts after an
  upgrade and the running Flux image differs from the pin, Coolify recreates
  the container. Sentinels reconnect by themselves.

### Coolify generates and stores the Flux secrets

- On the first start, Coolify generates the Ed25519 signing key pair, the key
  ID, and the internal token, and stores them encrypted in the database. The
  issuer is `APP_ID`.
- Coolify reads these values from the database. The `FLUX_*` environment
  variables stay as overrides, for development and for Coolify Cloud.
- Coolify gives Flux only the public signing key, the key ID, the issuer, and
  the internal token, as container environment variables.
- A key rotation uses the existing previous-key fields of Flux
  (`FLUX_PREVIOUS_SIGNING_KEY_ID`, `FLUX_PREVIOUS_SIGNING_PUBLIC_KEY`).

### The Flux URL is an instance setting

- A new instance setting holds the host and port that Nodes use to reach Flux.
  The default is the instance domain, or else the public IPv4 address, with
  port 7443 (the same order as `base_url()`).
- The Flux server certificate is issued for that host by the existing private
  CA. A change of the setting issues a new certificate and restarts Flux.
- Flux needs a direct TLS connection on its port. It does not run behind the
  proxy.

### Certificate renewal restarts Flux

- After `RenewFluxCertificate` or a CA rotation writes new files, Coolify
  restarts the Flux container. Sentinels reconnect within seconds.
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

- No change to `install.sh`, `upgrade.sh`, or `docker-compose.prod.yml`.
- Nodes need SSH access from Coolify to its own host (server `0`), as the
  proxy already does.
- Each certificate renewal and each Coolify upgrade with a new Flux pin drops
  the Node connections for a few seconds.

## Not decided here

- More than one Flux, regional Flux, and Coolify Cloud (roadmap P3). Cloud
  keeps its own deployment and the environment overrides.
- A hot reload of the Flux certificate.
- Flux on a host other than the Coolify host.
