---
type: fixed
---
Failed image scan of service images that a variable sets, such as `repo:${APP_RELEASE:-v1.0.0}` ([#12548](https://github.com/coollabsio/coolify/issues/12548))

- The scan uses the value of the service variable, else its default. The error of an image reference that is not valid names the resource and the image
