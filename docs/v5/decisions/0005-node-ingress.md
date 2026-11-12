# Decision 0005: Serve public HTTP through ingress Nodes

**Status: Accepted**, amended 2026-11-12. HTTPS is implemented. The
[amendment](#amendment-2026-11-12) records how the built design differs from the
HTTPS plan.

**Date: 2026-10-05**

## Context

Cluster applications run on Podman Nodes and can move between them. Users need
a public domain for an application that keeps working when the application
moves, when a Node fails, or when Coolify itself is offline.

The cluster already has the parts this needs. WireGuard connects the Nodes.
Corrosion replicates state between them. Sentinel publishes the healthy
containers of each workload to the `workload_endpoints` table in Corrosion.

## Decision

An operator marks one or more Nodes of a cluster as ingress Nodes. Each ingress
Node runs Caddy on port 80. Its local Sentinel installs, configures, and
supervises Caddy.

Coolify writes intent only:

- the domains and the container HTTP port of each cluster application;
- which Nodes are ingress Nodes.

Coolify sends the full route list of the cluster to each ingress Node with the
`ingress.reconcile.v1` command. It is the last step of the per-Node network
reconciliation and uses the cluster network revision. Sentinel stores the
routes in the Corrosion `ingress_routes` table and rejects a revision older
than the stored one. Caddy proxies each domain to the healthy containers of the
workload on any Node. It reads them from `workload_endpoints`, so routing
follows a move or a failed container without Coolify.

Every ingress Node serves every domain. There is no single point of failure: a
DNS record can point to several ingress Nodes, and an optional L4 load balancer
or DNS layer can sit in front of them.

Coolify opens the cluster firewall from the ingress Nodes to each routed
container port. A domain is unique in the whole Coolify instance.

### Moves without downtime

A move of a routed application is make-before-break:

1. Coolify allocates the target address and waits, for up to 120 s, until every
   reachable Node has applied the network revision that allows it. If that
   fails, the move fails and the source keeps running.
2. Coolify deploys the target, then waits 10 s. In that time the target
   endpoint replicates through Corrosion and every renderer (5 s interval)
   picks it up.
3. Before Sentinel stops or removes a container, it publishes the endpoint as
   `removing` and renders the local Caddy again. If another endpoint of the
   routed workload still serves, it waits 7 s, so the remote renderers drop
   the endpoint too.

A network revision does not restart Corrosion or recreate the WireGuard link
when their configuration is unchanged. Each revision only applies what changed.

### Sentinel versions

Coolify uses a new Sentinel behaviour only when the Node reports its
capability. Otherwise it keeps the old path. Turning on ingress requires
`ingress.reconcile.v1`. Nodes without it skip the ingress step. A Node that
supports it but is not an ingress Node receives `enabled: false`, which removes
Caddy.

### HTTPS plan

HTTPS is the next slice. The plan is (see the amendment for what was built):

- Certificates and keys live in Corrosion, encrypted with a cluster key, so
  every ingress Node can serve them.
- One ingress Node issues the certificate of a domain. It is chosen with a
  rendezvous hash over the healthy ingress Nodes, so all Nodes agree on the
  issuer without coordination.
- Other ingress Nodes forward HTTP-01 challenges for that domain to the issuer.
- HTTP-01 comes first. DNS-01 comes later for wildcard domains and for domains
  that do not point to the cluster yet.

## Consequences

- A domain change applies without a redeploy. It only bumps the cluster network
  revision.
- Ingress Nodes need port 80 open to the internet.
- The route list is limited to 10,000 domains per cluster and 20 domains per
  application.
- Coolify does not check DNS. The application page lists the ingress Node
  addresses for the user's A records.
- Only HTTP works until the HTTPS slice ships.

## Not decided here

This decision does not define:

- wildcard domains, path routing, or redirects;
- TCP or UDP ingress for non-HTTP services (see decision 0016);
- rate limiting, authentication, or other proxy middleware;
- an automatic DNS integration (see decision 0019).

## Amendment (2026-11-12)

HTTPS is implemented. Coolify commit `6424b62333` and the Sentinel modules
`crates/control/src/acme.rs` and `crates/control/src/ingress_tls.rs` contain
it. Two points of the HTTPS plan are unchanged:

- Certificates and their private keys are in the Corrosion table
  `tls_certificates`. Sentinel seals each private key with ChaCha20-Poly1305
  and a 32-byte cluster key. Coolify creates that key once and stores it
  encrypted in `node_clusters.ingress_tls_key`. It sends the key only to
  ingress Nodes, and it does not store the key in the operation request.
- The issuer uses HTTP-01 only. DNS-01 is not implemented.

Three points are different:

- **Issuer selection.** There is one issuer for the whole cluster, not one for
  each domain, and Coolify selects it. No rendezvous hash is used. The issuer
  is the oldest ingress Node that can receive network commands and reports
  `ingress.tls.v1` (`NodeCluster::tlsIssuerNode()`). `SyncNodeReachability`
  runs every minute, records the issuer in
  `node_clusters.ingress_tls_issuer_node_id`, and queues a network revision
  when the issuer changes. Only the issuer receives `tls_issuer`, the ACME
  directory, and the public addresses of the ingress Nodes. One issuer per
  cluster replaces a distributed lock. The ACME account is also in Corrosion
  (`acme_accounts`, sealed), so a new issuer uses the same account.
- **No challenge forwarding.** Ingress Nodes do not forward HTTP-01 requests
  to the issuer. The issuer writes each challenge answer to the Corrosion
  table `acme_challenges`, and every ingress Node serves it on port 80. Before
  the CA validates, the issuer requests the challenge from the public address
  of each ingress Node that the domain resolves to. It tries 30 times, every
  2 seconds.
- **DNS check before an order.** Coolify still does not check DNS. The issuer
  orders a certificate only for a domain that resolves to the public address
  of an ingress Node. Otherwise the status is `dns_mismatch`, and the issuer
  tries again after 10 minutes. This protects the failed validation limit of
  the CA. The public address of a Node is its `nodes.ip` value.

The other behaviour is:

- HTTPS is on when an ACME directory is set, the cluster has an ingress Node,
  and every Node of the cluster reports `ingress.tls.v1`
  (`NodeCluster::supportsIngressTls()`). Otherwise ingress serves HTTP only.
  `ACME_DIRECTORY_URL` defaults to the Let's Encrypt production directory. In a
  local environment the default is empty, so HTTPS is off. `scripts/dev-pebble`
  and `ACME_CA_FILE` set up a test CA.
- Caddy does not manage certificates. Sentinel writes the valid certificates
  of routed domains to files that only the Caddy user can read, and Caddy loads
  them with `load_files`. A domain with a certificate is served on port 443.
  Port 80 redirects it to HTTPS, unless the domain allows HTTP. A domain
  without a certificate stays on port 80. Ingress Nodes need ports 80 and 443
  open to the internet.
- The issuer checks once each minute. It renews a certificate in the last
  third of its lifetime and starts at most 5 orders in each check. After a
  failure it waits between 1 and 24 hours. A failed renewal keeps the old
  certificate until it expires. The issuer keeps the certificate of a removed
  domain for 7 days. Renewals continue while Coolify is offline.
- The issuer reports the state of each certificate (`issued`, `failed`,
  `dns_mismatch`, `orphaned`, or `pending`) in `system.info.v1`. Coolify stores
  the report in the Node metadata and shows it next to each domain of the
  application.

The domain settings of v4 are also implemented with `ingress.routes.v2`: a
port and a path prefix for each domain, a www redirect, the HTTP to HTTPS
redirect, the redirect type, and search indexing. Sentinel stores the routes in
the Corrosion table `ingress_http_routes`, not in `ingress_routes`. Coolify
sends the new fields only when every Node reports `ingress.routes.v2`
(`BuildNodeClusterIngressRoutes`). Wildcard domains are still rejected.

The consequence "Only HTTP works until the HTTPS slice ships" no longer
applies.
