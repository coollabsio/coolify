---
type: added
commit: 1703265353
---
API endpoints and MCP tools that manage image scanning and read the scan results

- `GET` and `PATCH /api/v1/servers/{uuid}/image-scanning`, `GET /api/v1/vulnerabilities` and `GET /api/v1/{applications|databases|services}/{uuid}/vulnerabilities`
- The MCP tools are `get_vulnerabilities` and `manage_image_scanning`
