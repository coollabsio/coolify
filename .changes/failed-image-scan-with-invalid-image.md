---
type: fixed
---
Failed image scan with `invalid image reference` for Compose images that use more than one variable, such as `${IMAGE_REPO:-ghcr.io/zammad/zammad}:${VERSION:-7.2.2-0000}` ([#12673](https://github.com/coollabsio/coolify/issues/12673))

- The scan resolves every variable of the image with its value or its default, as Docker Compose does
