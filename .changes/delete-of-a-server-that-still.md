---
type: changed
---
Delete of a server that still holds volumes or volume copies, which now needs a forced delete

- The `Danger zone` of the server page and `DELETE /api/v1/nodes/{uuid}` show the counts. A forced delete (`force=true` in the API) removes the server from Coolify, and the data stays on the server
