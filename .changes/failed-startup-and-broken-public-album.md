---
type: fixed
commit: 927e3f0b42
---
Failed startup and broken public album links in the Ente service templates ([#7405](https://github.com/coollabsio/coolify/issues/7405))

- An albums service with its own domain serves public links
- The images come from `ghcr.io/ente`, and the JWT secret is URL-safe so that Museum can decode it
- With S3, the template uses the routed `MINIO_SERVER_URL` as the S3 endpoint and allows all CORS origins
