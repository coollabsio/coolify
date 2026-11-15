---
type: fixed
---
Deployed the Docker Compose file of the base branch for pull request previews ([#5151](https://github.com/coollabsio/coolify/issues/5151))

- A preview uses the Docker Compose file of its pull request when `PR deployment access` is `Repository members only`. With public PR deployments or a raw `Compose deployment`, a preview still uses the file of the base branch, and the deployment log tells why
