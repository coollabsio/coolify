---
type: fixed
---
Missing `activity_id` for services in the `POST /api/v1/deploy` response, so a service start could not be polled ([#12687](https://github.com/coollabsio/coolify/issues/12687))

- A deploy by tag lists each service with its `activity_id` in `details`, and a service with a start in progress is not started a second time
