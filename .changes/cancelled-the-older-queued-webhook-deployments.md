---
type: changed
breaking: true
commit: cf1e0e70c5
---
Cancelled the older queued webhook deployments of an application by default when a newer push arrives, so only the latest commit is built ([#10921](https://github.com/coollabsio/coolify/discussions/10921), [#7678](https://github.com/coollabsio/coolify/discussions/7678))

- It works per application, pull request and server
- `Advanced` > `Deployment` and `is_auto_cancel_queued_deployments_enabled` in the applications API turn it off
- Manual, API, restart and rollback deployments are never cancelled
- An opt-in setting, `is_auto_cancel_building_deployments_enabled` in the API, also stops an older webhook deployment that is still building, a deployment that started to replace containers always finishes
- `cancel_previous` (`none`, `queued` or `building`) on `/api/v1/deploy` and the MCP `deploy` tool cancels the older webhook, API and MCP deployments, the default is `none`
