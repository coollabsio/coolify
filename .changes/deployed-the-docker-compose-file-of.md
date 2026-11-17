---
type: fixed
---
Deployed the Docker Compose file of the base branch for pull request previews ([#5151](https://github.com/coollabsio/coolify/issues/5151))

- A preview of a branch in the same repository uses the Docker Compose file of its pull request when `PR deployment access` is `Repository members only`. The file changes only that preview, not the variables and file storages of the application. Public PR deployments, a raw `Compose deployment`, and previews added by number or through the API use the file of the base branch, and the deployment log tells why
