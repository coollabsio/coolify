---
type: added
---
`GET /api/v1/servers/{uuid}/metrics` endpoint for the CPU, memory, disk, network, and load history that Sentinel collects

- The `minutes` query parameter, or `from` with an optional `to`, sets the range, which cannot start before the Sentinel metrics retention of the server
- The `types` query parameter selects the metric types and defaults to `cpu,memory`
