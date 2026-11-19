---
type: fixed
---
Failed first deployment and `500` errors after the setup of the `Windmill` service template, which is available again ([#6612](https://github.com/coollabsio/coolify/issues/6612))

- The template uses the stable Windmill `1.875.0` image instead of `main`, starts the workers one after the other, and no longer mounts the Docker socket into the workers
