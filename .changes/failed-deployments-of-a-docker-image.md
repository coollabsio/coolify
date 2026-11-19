---
type: fixed
---
Failed deployments of a `Docker Image` application whose image is only on the server and not in a registry ([#6122](https://github.com/coollabsio/coolify/issues/6122))

- When the pull fails, the deployment starts the image that is already on the server and logs a warning
