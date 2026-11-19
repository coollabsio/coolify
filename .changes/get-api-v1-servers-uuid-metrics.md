---
type: added
---
`GET /api/v1/servers/{uuid}/metrics` endpoint for the CPU and memory history that Sentinel collects

- The `minutes` query parameter sets the range and cannot exceed the Sentinel metrics retention of the server
