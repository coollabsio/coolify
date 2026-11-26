---
type: added
---
Pagination with `page` and `per_page` for the `GET /api/v1/deployments/applications/{uuid}` API endpoint

- The default is 10 deployments for each page, and the maximum is 100
- The `skip` and `take` query parameters still work, but are deprecated
