---
type: added
---
Versioned `coolify.json` files that define the resources of a project environment

- A push to the linked branch applies safe changes; removals and other destructive changes wait for approval on the environment `coolify.json` page
- Settings that the file manages are read-only in Coolify; a team admin can suspend the link
- Variables in the file use `value`, `required`, `generate`, or `ref`, so secret values stay out of the repository
- Export an environment from the `coolify.json` page or `GET /api/v1/environments/{uuid}/config/export` to start
