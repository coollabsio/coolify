---
type: added
---
Several named secret manager sources for one resource, referenced as `{{vault.<source>.KEY}}` in variables and set as a list in `secret_manager` of `coolify.json` ([#12641](https://github.com/coollabsio/coolify/issues/12641))

- `{{vault.KEY}}` keeps reading the `default` source. The secret manager API and MCP tool take an optional `name`, and the `Secret manager` section lists the other sources
