---
type: fixed
---
Saved a service Compose file that Docker Compose rejects, for example a service indented under another service, without an error ([#6653](https://github.com/coollabsio/coolify/issues/6653))

- `Save changes` in `Edit Compose file` and the `docker_compose_raw` field of the services API (create and update) check the generated file with `docker compose config` on the server. A rejected file is not saved, and the API returns `422`. The check is skipped when the server is not reachable
