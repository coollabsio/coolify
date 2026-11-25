---
type: changed
breaking: true
---
Skipped Git push deployments of applications that the pushed changes do not affect

- Coolify compares each push with the deployed commit and detects the files each application uses from its build context, Dockerfile, Compose file, and workspace dependencies. Turn it off per application with `Smart deploy triggers`, or for the instance in `Settings` > `Advanced`
- Coolify keeps a copy of each repository without file contents in the `coolify-git-mirrors` Docker volume of a server of the team, and removes copies that are unused for 14 days
