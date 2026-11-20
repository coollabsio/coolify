---
type: fixed
---
Saved Caddy dynamic configuration files that Caddy rejects, which broke the proxy on its next start ([#5640](https://github.com/coollabsio/coolify/issues/5640))

- A file that Caddy rejects keeps its previous version. When Caddy rejects a global options block, the error tells you to set global options as `caddy.*` labels of the proxy container
