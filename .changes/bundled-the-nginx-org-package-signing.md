---
type: maintenance
---
Bundled the nginx.org package signing key in the Docker images, because nginx.org no longer serves it for download

- Image builds without a cached layer failed while they installed nginx
