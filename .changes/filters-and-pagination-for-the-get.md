---
type: added
---
Filters and pagination for the `GET /api/v1/applications`, `GET /api/v1/databases`, and `GET /api/v1/services` API endpoints ([#5567](https://github.com/coollabsio/coolify/issues/5567))

- The `project_uuid`, `environment_uuid`, `server_uuid`, `status`, and `name` query parameters filter the list. Applications also filter by `tag`. With `page` or `per_page` (default 50, max 100), the response is paginated and the `X-Total-Count` header gives the total
- The application previews, scheduled task executions, backup executions, Docker cleanup executions, and vulnerabilities lists accept `page` and `per_page` too. The Docker cleanup executions list returns 20 runs for each page
