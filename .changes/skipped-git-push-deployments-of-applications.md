---
type: changed
breaking: true
---
Skipped Git push deployments of applications that the pushed changes do not affect

- Coolify compares each push with the deployed commit and detects the files each application uses from its build context, Dockerfile, Compose file, and workspace dependencies. Turn it off per application with `Smart deploy triggers`, or for the instance in `Settings` > `Advanced`
