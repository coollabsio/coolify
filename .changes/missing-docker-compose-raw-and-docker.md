---
type: fixed
---
Missing `docker_compose_raw` and `docker_compose` in the MCP `get_service` tool for `read:sensitive` tokens ([#12525](https://github.com/coollabsio/coolify/issues/12525))

- A `read` token without `read:sensitive` still does not get the compose files
