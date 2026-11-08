---
type: fixed
commit: 6254f44f16
---
Empty disk usage on the `Metrics` tab, the `Fleet` page and in the MCP `get_server_metrics` tool when Sentinel reports the Coolify data disk under another mount point than `/`, `/data` or `/data/coolify` ([#12448](https://github.com/coollabsio/coolify/issues/12448))

- This occurs for example on a NAS where `/data` is a symlink into a data volume
