---
type: added
---
Rollback of a cluster application to an earlier revision from its `Deployments` page

- Only the image rolls back. Environment variables, secrets, and other settings keep their current values, and volume data is not rolled back. When the cluster registry no longer has the image, Coolify builds the same commit again
