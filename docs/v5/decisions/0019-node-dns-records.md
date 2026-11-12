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
