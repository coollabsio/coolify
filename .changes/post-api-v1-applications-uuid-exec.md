---
type: added
---
`POST /api/v1/servers/{uuid}/exec` and `POST /api/v1/applications/{uuid}/exec` endpoints that run a one-shot shell command on a server or in an application container and return its exit code, stdout, and stderr

- The endpoints need a token with the new `terminal` permission. Only team admins and owners can create it, it cannot be combined with other permissions, a `root` token does not include it, and it expires after at most 90 days
- A `terminal` token can be limited to selected projects, environments, resources, or servers. A server limits it to the server endpoint, and a project, environment, or resource limits it to the containers of those applications
- The `Terminal API` setting must be enabled in `Settings` > `Advanced` and in the team settings. Both are off by default
- The server must have terminal access enabled. A command runs for at most 10 seconds. Docker Swarm applications are not supported
