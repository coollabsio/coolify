---
type: fixed
---
Ignored a `sudo docker login` on non-root servers when the helper container pulled private images ([#6398](https://github.com/coollabsio/coolify/issues/6398))

- Without a Docker config of the SSH user, deployments use the Docker config of root, like the image pulls on the server
