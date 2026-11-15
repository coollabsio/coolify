# Decision 0019: Manage DNS records of cluster domains with the v4 DNS providers

**Status: Accepted**

**Date: 2026-11-11**

## Context

Decision 0005 does not check DNS. The user creates A records to the ingress
Node addresses. v4 already manages DNS records in provider zones
(`DnsProviderZone`, `ManagedDnsRecord`) and changes only records that it
created.

## Decision

- When a domain of a cluster application is in a zone that the team manages,
  Coolify creates A and AAAA records to the addresses of the ingress Nodes.
- Coolify updates the records when the ingress Nodes change, and deletes them
  when the domain is removed.
- Coolify changes only records that it created, with the v4 owner rule.

## Consequences

- Domains of cluster applications work without manual DNS changes.

## Not decided here

- DNS-01 certificates for wildcard domains.

## Implementation

- The user decided: the Cloudflare proxy is off for cluster records, because
  Caddy gets certificates with HTTP-01. The records of an offline ingress Node
  stay. Coolify does not move them to other Nodes (no failover, P7).
- A hostname gets records when it is a domain of the cluster application, or
  the source of its www redirect, and exactly one zone of the workload's team
  manages it, and the token has automatic DNS on. Generated sslip.io domains
  and domains without a zone are skipped.
- The records point to the public addresses of the ingress Nodes of the
  workload's cluster (`Node::ip`). The v4 rule of `ServerDnsAddresses`
  applies: private, reserved, CGNAT, and link-local addresses are left out.
  An IPv4 address gets an A record, an IPv6 address gets an AAAA record.
- Several ingress Nodes give one record per address for the same name
  (round-robin). One `ManagedDnsRecord` row is one provider record, as in v4.
  Each row has a `ManagedDnsRecordReference` to the workload. The schema does
  not change.
- `CloudflareDnsProvider::createRecord()` has a `roundRobin` flag. With it, a
  record of the same name that Coolify created for the same workload (owned
  row and ownership comment) is not a conflict. Any other record of the name
  is a conflict, as in v4. Coolify does not change it, and the Domains page
  shows the problem. v4 resources do not use the flag, so they work as
  before.
- `NodeWorkloadDnsRecords::sync()` converges one workload. It releases the
  records of removed hostnames and of addresses that are no longer ingress
  addresses, then creates the missing records. The release uses
  `ManagedDnsRecordCleanup`, so only owned records with the ownership comment
  are deleted, and a record that the user created is only forgotten. A
  workload without a cluster keeps the records of its current hostnames.
  All provider changes run in the v4 hostname lock
  (`ManagedDnsHostnameLock`), so a cluster record and a v4 record of the same
  name cannot race.
- `SyncNodeClusterDnsRecordsJob` syncs every workload of a cluster (unique per
  cluster until it runs). These events queue it:
  - `QueueNodeClusterNetworkRevision`: a domain save
    (`UpdateNodeWorkloadDomains`), a deployment or a move that changes the
    placements, a preview deploy, a delete, and an ingress change
    (`SetNodeIngress`).
  - `Node` saved: a change of `is_ingress`, `ip`, or `node_cluster_id` of an
    ingress Node (for the old and the new cluster), and the delete of an
    ingress Node.
- A deleted workload (also a preview and a service of a stack) releases its
  records with the v4 `ReleasesManagedDnsRecords` trait. The scheduled orphan
  cleanup also releases records whose workload is gone.
- `ManagedDnsRecordCleanup` knows cluster applications. A record stays while a
  cluster application of any team uses its hostname.
- The **Domains** page of a cluster application shows a **DNS records**
  section: the DNS provider and zone, the records with their owner, the
  status, and the problem of each hostname. **Create records** creates the
  missing records when automatic DNS is off or several providers manage the
  domain. The page authorizes on the workload, and zones come from the
  workload's team.
- Server transfer does not move cluster data. The records of a cluster
  application are not referenced by v4 resources, so the export does not
  include them.

Deviations:

- The v4 **Replace record** action for a conflict is not on the cluster page.
  A conflict is reported only.
- Problems of the background sync are kept in the cache for 7 days, not in
  the database.
- Not tested against Cloudflare. Tested with a fake Cloudflare API.
