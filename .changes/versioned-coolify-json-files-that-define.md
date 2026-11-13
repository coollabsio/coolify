---
type: added
---
Versioned `coolify.json` files that define the resources of a project environment

- A push to the linked branch applies safe changes; removals and other destructive changes wait for approval on the `Config as code` page of the environment
- Settings that the file manages are read-only in Coolify; a team admin can pause the sync
- Variables in the file use `value`, `required`, `generate`, or `ref`, so secret values stay out of the repository
- When a new repository has a `coolify.json`, the add form creates the resources from the file, or you configure them manually
- The project, environment, and resource pages show the sync state, and the variables list tags the variables that the file sets
- Export an environment from the `Config as code` page or `GET /api/v1/environments/{uuid}/config/export` to start. Export needs a team admin or the `read:sensitive` token ability, because commands and Compose files can hold credentials
