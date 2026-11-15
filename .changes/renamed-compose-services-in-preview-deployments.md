---
type: fixed
---
Renamed Compose services in preview deployments, which broke multiple Compose files, `depends_on` merges, and custom start commands that use the service names ([#6070](https://github.com/coollabsio/coolify/issues/6070))

- A preview keeps the service names of the Compose file, and `SERVICE_NAME_*` is the Compose service name. Each preview still has its own container names, network, and project. The `<name>-pr-<id>` name stays a hostname on the preview network
- With `Connect to Predefined Network`, or a Compose network with `name:` or `external:`, a preview still renames its services to `<name>-pr-<id>`, because production uses the same network and the same service names
- A preview on a shared network writes a warning to the deployment log when an environment value uses a service name as a hostname. Use `${SERVICE_NAME_<SERVICE>}` as the hostname instead
