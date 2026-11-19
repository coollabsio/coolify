---
type: fixed
---
Mounted empty directories in preview deployments of Docker Compose applications with `Preserve Repository During Deployment` enabled ([#5084](https://github.com/coollabsio/coolify/issues/5084))

- Each preview deployment uses its own directory (`<uuid>-pr-<id>`), and Coolify deletes it with the preview
