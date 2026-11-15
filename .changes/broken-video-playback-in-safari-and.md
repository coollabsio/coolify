---
type: fixed
---
Broken video playback in Safari and broken seeking in other browsers for files served through Traefik with `Gzip` enabled ([#5222](https://github.com/coollabsio/coolify/issues/5222))

- Traefik does not compress video, audio, images, PDF, and archive files anymore, so `Accept-Ranges` stays and range requests return the original bytes. Redeploy a resource to use the new `gzip-no-media` middleware
