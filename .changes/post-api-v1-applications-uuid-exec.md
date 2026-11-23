---
type: added
---
`POST /api/v1/applications/{uuid}/exec` and `POST /api/v1/servers/{uuid}/exec` endpoints that run a one-shot shell command and return its exit code, stdout, and stderr

- The endpoints need a token with the new `terminal` ability. Only team admins and owners can create it, a `root` token does not include it, and it expires after at most 90 days. The `Terminal API` setting on the team page is off by default. The server must have terminal access enabled. A command runs for at most 10 seconds
