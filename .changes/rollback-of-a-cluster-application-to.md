---
type: added
---
Rollback of a cluster application to an earlier revision from its `Deployments` page and the API

- Only the image rolls back. Environment variables, secrets, and other settings keep their current values, and volume data is not rolled back. When the cluster registry no longer has the image, Coolify builds the same commit again
- `GET /api/v1/cluster-applications/{uuid}/revisions` lists the revisions without their environment variables or secrets, and `POST /api/v1/cluster-applications/{uuid}/rollback` rolls back to one of them with the `deploy` ability
