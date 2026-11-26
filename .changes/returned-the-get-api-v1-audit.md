---
type: changed
breaking: true
---
Returned the `GET /api/v1/audit-events`, `GET /api/v1/vulnerabilities`, and backup executions API lists as plain arrays, with the total in the `X-Total-Count` header

- The audit events response has no `data`, `current_page`, `last_page`, `per_page`, and `total` fields. The vulnerabilities list has no `resources` wrapper, and the backup executions list has no `executions` wrapper. The vulnerabilities of one resource have no `pagination` field. All paginated lists accept `page` and `per_page`
