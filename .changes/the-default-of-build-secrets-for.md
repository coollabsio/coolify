---
type: changed
---
The default of `Build secrets` for new applications to `Docker BuildKit secrets`, so that build-time values stay out of the image layers

- Existing applications keep their setting. A cloned application and an application from a server transfer keep the setting of their source
- The `use_build_secrets` field in the applications API defaults to `true`
- The deployment log shows a warning when the Dockerfile pins `docker/dockerfile` older than 1.10, or uses a build-time variable outside a `RUN` instruction, because these do not work with build secrets
