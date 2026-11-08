---
type: fixed
commit: e29dcf70d9
---
Applications and databases removed from the Docker Compose file of a service showing as `Exited` and keeping the service degraded ([#9591](https://github.com/coollabsio/coolify/issues/9591))

- They show `Not in Compose file` and do not count in the service status
- Coolify keeps their settings and uses them again when you add the service back under the same name
- To remove one, delete it in its settings
- Existing ones are marked at the next deployment or save of the service
