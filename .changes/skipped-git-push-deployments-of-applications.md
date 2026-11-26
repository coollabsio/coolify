---
type: changed
breaking: true
---
Skipped Git push deployments of applications that the pushed changes do not affect

- Applications that build from part of a repository are checked: a base directory, a Dockerfile or Compose file in a subdirectory, a workspace filter in a command, or another application on the same repository and branch. Coolify compares each push with the deployed commit and detects the files each application uses from its build context, Dockerfile, Compose file, and workspace dependencies. Every other application deploys on each push
- Choose which pushes deploy an application in `General` > `Deploy triggers` > `Deploy on`: `Every push`, `Smart deploy triggers`, or `Watch paths`. Turn smart deploy triggers off for the instance in `Settings` > `Advanced`
- Coolify keeps a copy of each repository without file contents in the `coolify-git-mirrors` Docker volume of a server of the team, and removes copies that are unused for 14 days
