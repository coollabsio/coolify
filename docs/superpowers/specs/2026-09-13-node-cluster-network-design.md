# Node Cluster Network Design

## Scope

Add team-owned clusters that group Nodes and provide the authority for a full-mesh WireGuard network. Coolify stores desired membership and security policy. Sentinel applies and observes host state. Corrosion distributes runtime endpoint discovery only.

## Cluster and membership

A Node belongs to zero or one cluster. Legacy Servers cannot join clusters. A cluster has a name, description, IPv4 CIDR, WireGuard interface and UDP port, desired revision, and observed health. Coolify allocates an unused `/24` from `10.240.0.0/12`; a user can override it with a private IPv4 CIDR before activation. Active CIDR changes require a later migration operation. Node addresses are stable and unique within the cluster.

## Topology

The first release uses a full mesh with a documented limit of 100 Nodes per cluster. Each host creates its private key locally. Coolify stores public keys and builds peer configuration. The model can later add gateways without changing cluster membership.

## Firewall

Sentinel owns only a dedicated Coolify nftables table. It does not flush or replace UFW, firewalld, or user rules. Configuration is staged, validated, activated with a rollback timer, and confirmed only after control connectivity and peer checks. SSH remains the recovery path.

## Corrosion

Corrosion runs as a separate host-native systemd service managed by Sentinel. Each cluster is a separate gossip group over WireGuard. Corrosion stores replicated workload endpoint, owner, state, and health data. It is not authoritative for clusters, Node membership, WireGuard peers, or desired firewall policy.

## UI

The server area gets a Clusters list and cluster detail page. Users can create clusters, edit inactive network settings, assign unclustered Nodes, and remove Nodes through explicit actions. Pages show CIDR, Node count, private addresses, desired/applied revisions, WireGuard health, Corrosion health, and durable operations. Node pages show cluster and network state.

## Delivery slices

1. Cluster CRUD, authorization, UI, membership, and address allocation.
2. Sentinel WireGuard key and observation protocol.
3. Durable full-mesh reconciliation and rollback.
4. Dedicated nftables reconciliation.
5. Corrosion installation, discovery schema, and health.
6. Internal DNS and two-QEMU-Node integration verification.
