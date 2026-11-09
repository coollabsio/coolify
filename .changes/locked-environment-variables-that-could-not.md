---
type: fixed
commit: da7779d061
---
Locked environment variables that could not be changed, unlocked or deleted when Docker Compose uses them ([#6803](https://github.com/coollabsio/coolify/issues/6803))

- A locked value can be replaced without showing it
- The lock is permanent in the UI, the API and MCP, and `Lock` asks for confirmation
