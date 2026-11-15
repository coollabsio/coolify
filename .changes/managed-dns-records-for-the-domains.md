---
type: added
---
Managed DNS records for the domains of cluster applications

- When one connected Cloudflare zone of the team with automatic DNS manages a domain, Coolify creates an A or AAAA record for each public address of the ingress servers, without the Cloudflare proxy
- The `Domains` page of a cluster application shows the records and their status
