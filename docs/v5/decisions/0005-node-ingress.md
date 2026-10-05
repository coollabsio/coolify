# Decision 0005: Serve public HTTP through ingress Nodes

**Status: Accepted** for the HTTP-only slice. HTTPS is planned below and is not
implemented.

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

HTTPS is the next slice. The plan is:

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
- TCP or UDP ingress for non-HTTP services;
- rate limiting, authentication, or other proxy middleware;
- an automatic DNS integration.
