---
type: fixed
---
Returned a `deployment_uuid` that does not exist from `POST /api/v1/deploy` when the commit is already queued, so `GET /api/v1/deployments/{uuid}` answered 404 ([#12686](https://github.com/coollabsio/coolify/issues/12686))

- The deploy, start, and restart endpoints return the uuid of the deployment that is already queued or in progress. Start and restart answer 429 when the deployment queue is full
