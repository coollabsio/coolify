---
type: added
---
`activity_id` in the response of the service `start` and `restart` API endpoints, and `GET /services/{uuid}/activities/{activity_id}` to read the status and output of that run ([#11904](https://github.com/coollabsio/coolify/discussions/11904))

- The output is only shown to team admins with a token that has the `read:sensitive` ability
- `start` and `restart` return 400 while another start or restart of the service is in progress, as the UI does
