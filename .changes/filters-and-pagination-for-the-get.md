---
type: added
---
Filters and pagination for the `GET /api/v1/applications`, `GET /api/v1/databases`, and `GET /api/v1/services` API endpoints ([#5567](https://github.com/coollabsio/coolify/issues/5567))

- The `project_uuid`, `environment_uuid`, `server_uuid`, `status`, and `name` query parameters filter the list. Applications also filter by `tag`. With `page` or `per_page` (default 50, max 100), the response is paginated and the `X-Total-Count` header gives the total
- `GET /api/v1/deployments/applications/{uuid}` accepts `page` and `per_page` (default 10, max 100). `skip` and `take` still work, but are deprecated
