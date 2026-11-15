---
type: added
---
Preview deployments of pull requests for cluster applications from Git, on the new `Previews` tab

- Pull request webhooks of the GitHub App and of GitHub, GitLab, Gitea, Forgejo, and Bitbucket deploy each pull request as a preview application with a generated domain, and remove it when the pull request closes
- Previews are off by default. Pull requests from forks deploy only when `Pull requests from forks` is on, and then get only the preview variables
- With a GitHub App, Coolify comments the status and the link on the pull request, and removes the previews of closed pull requests once a day
