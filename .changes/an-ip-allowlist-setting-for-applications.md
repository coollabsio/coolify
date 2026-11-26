---
type: added
---
An `IP allowlist` setting for applications that blocks clients outside the listed IP addresses and CIDR ranges at the proxy

- The setting is in the `Authentication` section of the application settings and in the `ip_allowlist` API field
- After a redeploy, Traefik and Caddy return HTTP 403 to other clients, also on preview deployments
