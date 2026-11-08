---
type: fixed
commit: bb6dfd147e
---
Two deployments of one application running at once when started from the MCP `deploy` tool or the MCP `control` start action, which could stop the application ([#12442](https://github.com/coollabsio/coolify/issues/12442))

- They queue behind a deployment in progress, as the API and the `Deploy` button do
- A deployment of the same commit is skipped, and the response gives the UUID of the deployment in progress
- A rolling update removes only the containers that ran before it started
