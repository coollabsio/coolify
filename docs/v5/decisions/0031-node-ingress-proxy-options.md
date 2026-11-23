# Decision 0031: Proxy options for cluster domains

**Status: Proposed**

**Date: 2026-11-23**

## Context

Decision 0005 left "rate limiting, authentication, or other proxy middleware"
open. This decision covers basic auth, custom proxy labels or middlewares, and
custom proxy configuration for the domains of cluster applications and stack
services.

### v4

A v4 application has these proxy settings:

- Basic auth: `is_http_basic_auth_enabled`, `http_basic_auth_username`,
  `http_basic_auth_password` (cast `encrypted`, so Coolify can show it),
  `http_basic_auth_hash_algorithm` (`bcrypt` or `argon2id`),
  `http_basic_auth_bcrypt_cost` (4 to 14), and the Argon2id memory cost (up to
  256 MiB) and time cost. `fqdnLabelsForTraefik` and `fqdnLabelsForCaddy`
  (`bootstrap/helpers/docker.php`) hash the password on each label build and
  write it into a container label.
- `is_gzip_enabled` and `is_stripprefix_enabled` (also on
  `ServiceApplication`).
- `custom_labels`: free text. The labels can add any Traefik or Caddy router
  or middleware to the proxy of the server.
- Dynamic configuration files of the server proxy
  (`App\Livewire\Server\Proxy\DynamicConfigurations`). Only a team admin can
  change them (`ServerPolicy::update`).

`custom_nginx_configuration` configures the Nginx inside a static image. It is
not a proxy setting, so this decision does not cover it.

### v5 today

- A cluster belongs to one team (`node_clusters.team_id`).
  `BuildNodeClusterIngressRoutes::routedWorkloads()` reads only the workloads
  of that team. So an ingress Node serves the routes of one team only.
- `UpdateNodeWorkloadDomains` saves `node_workloads.domain_routes`,
  `www_redirect`, `force_https`, and `permanent_redirect`. It needs
  `NodeWorkloadPolicy::update`, so a member with a write grant on the workload
  can change them. A host belongs to one workload of the team.
- `BuildNodeClusterIngressRoutes` sends one route for each host and path, plus
  the www redirects, with `ingress.reconcile.v1`. The fields of
  `ingress.routes.v2` are `path`, `redirect_host`, `http_only`, `allow_http`,
  `temporary_redirect`, `noindex`, and `strip_path` (always on when a path is
  set).
- Sentinel stores the routes in the Corrosion table `ingress_http_routes`
  (`crates/control/src/network.rs`). Every Node of the cluster has this table,
  also a Node that is not an ingress Node.
- Sentinel renders one Caddy JSON configuration for all routes
  (`render_caddy_config` in `crates/control/src/ingress.rs`) and loads it
  through the admin socket of Caddy `v2.11.7` (`apply_config`,
  `load_into_caddy`). A route already uses Caddy handlers: `headers` for
  `noindex`, `rewrite` for `strip_path`, `static_response` for redirects, and
  `reverse_proxy`. If Caddy refuses a configuration, no route of the cluster
  gets the change.
- Sentinel validates the whole route list (`validate_routes`). One invalid
  route fails the full list.
- Capabilities have the form `ingress.<feature>.v<n>`: `ingress.reconcile.v1`,
  `ingress.tls.v1`, `ingress.routes.v2` (`crates/protocol/src/lib.rs`).
- The stack mapper (`NodeStackComposeMapper`, `NodeStackCompose`) does not
  read `labels:`. It ignores them without a note.

### Templates

1 of 388 templates in `templates/compose` has proxy labels: `mosquitto` has
two `traefik.tcp.routers.*.entrypoints` labels for MQTT. That is TCP, which
Nodes publish with port publishing (decision 0016). No template has a
`caddy_` label or a basic auth label. `chibisafe` runs its own Caddy as a
service, which is a normal workload.

## Decision

Add structured proxy options to each route. Do not allow free-form Caddy
configuration or proxy labels.

### Options

The options belong to the workload, like `www_redirect`, and apply to every
route of the workload. A preview workload (decision 0025) copies the options
of its parent application.

| Option | Coolify column on `node_workloads` | Caddy handler that Sentinel renders | Rules |
| --- | --- | --- | --- |
| Basic auth | `is_http_basic_auth_enabled`, `http_basic_auth_username`, `http_basic_auth_password_hash` | `authentication` with `http_basic`, hash cache on | Bcrypt only, cost 10 to 12. Username: 1 to 64 printable ASCII characters, no `:` |
| IP allow list | `ingress_allowed_ips` (JSON) | `remote_ip` matcher, else `403` | IPv4 and IPv6 addresses and CIDR ranges; at most 32 |
| Request headers | `ingress_request_headers` (JSON) | `headers` with `request.set` | At most 16 |
| Response headers | `ingress_response_headers` (JSON) | `headers` with `response.set` | At most 16 |
| Compression | `is_gzip_enabled` (default on) | `encode` with `zstd` and `gzip` | — |
| Strip the path | `is_stripprefix_enabled` (default on) | the existing `rewrite` | Replaces "always on" for `strip_path` |

The www redirect, the HTTP to HTTPS redirect, the redirect type, and
`noindex` stay as they are (`ingress.routes.v2`).

### Rules for the options

- Coolify hashes the password once, on save, with `password_hash` and
  `PASSWORD_BCRYPT`. It stores the hash only, not the password. The hash stays
  the same until the user sets a new password, so the route list does not
  change on each network revision. The page shows "Password is set" and lets
  the user set a new one. **Generate** makes a random password and shows it
  one time.
- A header name is an HTTP token. A value is at most 1024 printable ASCII
  characters. Coolify refuses these names: `Host`, `Connection`, `Upgrade`,
  `Keep-Alive`, `Transfer-Encoding`, `Content-Length`, `TE`, `Trailer`,
  `Proxy-*`, and `X-Forwarded-*`.
- Sentinel writes each header value as literal text. A Caddy placeholder in a
  value does not expand, because a placeholder can read data of the ingress
  Node.
- The order in a route is: IP allow list, basic auth, headers, compression,
  strip the path, then the proxy. A www redirect route has no options. It
  sends the client to the protected host.
- The ACME challenge answers of the issuer (decision 0005) stay outside of
  the routes, so auth and the allow list do not stop a renewal.
- Coolify validates every rule on save (`UpdateNodeWorkloadDomains`, the
  Livewire page, and the API of decision 0029). Sentinel validates the same
  rules again. `BuildNodeClusterIngressRoutes` skips a route that fails them,
  like it skips an invalid host today.

### Sentinel

- One new capability, `ingress.route_options.v1`, for all options. One
  Sentinel version adds all of them, like `workload.runtime_options.v1` in
  decision 0028.
- New fields on `IngressRoute` in `control.proto`. Sentinel stores them in
  new columns of `ingress_http_routes`, in the same transaction as the route.
- The API never returns the hash. In the API, the password is write-only.

### Versions fail closed

An older Sentinel ignores the new fields and serves the route without them.
So:

- Coolify sends the options only when every Node of the cluster reports
  `ingress.route_options.v1` (`NodeCluster::supportsIngressRouteOptions()`,
  like `supportsIngressRoutesV2()`).
- Without the capability, Coolify refuses to save a protected option (basic
  auth, IP allow list). If a Node without the capability joins later,
  `BuildNodeClusterIngressRoutes` leaves out each route of a workload with a
  protected option. The page shows the reason. A protected domain is offline,
  not public.
- Without the capability, Coolify sends headers, compression, and the path
  setting as they are today, and shows a note that they do not apply.

### Compose labels

- Coolify does not translate `traefik.*` or `caddy*` labels. A label set is a
  second, partial proxy language with many forms.
- The stack mapper adds a note to the service when a label starts with
  `traefik.` or `caddy`: "Proxy labels are ignored on Nodes. Use the domain
  settings of the service." Other labels stay ignored without a note.
- The `mosquitto` labels get this note. Its MQTT ports use port publishing.

## Options compared

| Option | Effect on other routes of the ingress | Boundary crossed | Role needed | Result |
| --- | --- | --- | --- | --- |
| A. Structured options in the route (this decision) | None. Each option is inside the matcher of its own host and path | None | Member with a write grant on the workload | Chosen |
| B. Free-form Caddy JSON for each workload | Can match other hosts, read the certificates and keys of the cluster, or make Caddy refuse the configuration for all routes | Member with grants -> resources outside the grants, and the ingress Node for a member whose grants do not include it | Member with a write grant | Rejected |
| C. Free-form Caddy JSON for each cluster | Same as B | None. A team admin has root on the Nodes already | Team admin (`NodeClusterPolicy::update`) | Rejected for now. See "Not decided here" |
| D. Translate Traefik and Caddy labels of Compose | Same as B for each label that is not mapped strictly | Same as B | Member with a write grant | Rejected. 0 templates need it |
| E. Store the password, hash on each render | None | None | Member with a write grant | Rejected. A new salt on each render changes the route list each time, and the password must go to Sentinel |

## Security

- **Another team.** No option crosses this boundary. A cluster and its
  ingress Nodes belong to one team, and `routedWorkloads()` reads only that
  team.
- **Members with grants.** The ingress Nodes are shared by all workloads of
  the cluster. A member with a write grant on one workload is not root on an
  ingress Node that runs none of the granted workloads. Free-form
  configuration (options B and D) would give that member control of the
  traffic and the certificates of other workloads. Structured options cannot
  do this: Sentinel renders each option inside its own route, and a host
  belongs to one workload.
- **Hashes in Corrosion.** Every Node of the cluster replicates
  `ingress_http_routes`. Root on any Node can read the hashes. This includes a
  member whose grant gives root on a workload Node. So Corrosion holds only
  hashes, never a password, and **Generate** gives a strong password by
  default.
- **Header values are not secret.** They are in Corrosion as plain text. The
  page tells the user not to put a token in a header.
- **Load on a shared ingress.** Each failed basic auth check costs CPU of the
  ingress Node. The cost limit (bcrypt 10 to 12) keeps one workload from
  slowing the routes of other workloads. Argon2id is not available, because
  the v4 limit of 256 MiB for each check is too large for a shared ingress.
- **Fail closed.** A cluster with an older Sentinel never serves a protected
  route without its protection.

## Consequences

- The v4 basic auth, compression, and strip prefix settings work on cluster
  applications and stack services.
- Users who need the v4 Argon2id option use bcrypt on Nodes.
- An ingress Node needs a Sentinel upgrade before a protected domain works.
- Compose files with proxy labels show a note instead of a silent drop.
- Users who need a Caddy feature that is not in the table need a new
  structured option and a new capability.

## Not decided here

- Rate limiting. Stock Caddy `v2.11.7` has no rate limit handler.
- The client address behind a load balancer or a CDN proxy (trusted proxies,
  PROXY protocol). The IP allow list uses the address of the peer.
- Custom redirect rules (a path to another URL) and rewrites other than the
  path strip.
- Forward auth to an external identity provider.
- A cluster-level Caddy configuration for team admins (option C).
- More than one basic auth user for each workload.
