---
type: fixed
---
Ignored `Force deploy (without cache)` and `Disable Build Cache` for Docker Compose applications with a `Custom Build Command` ([#5336](https://github.com/coollabsio/coolify/issues/5336))

- Coolify adds `--no-cache` to the build command when the command does not have it
