---
type: fixed
---
Failed deployments of GitHub App, GitLab, and Gitea sources with private submodules that have SSH URLs ([#4252](https://github.com/coollabsio/coolify/issues/4252))

- Coolify now sends the token of the source also to submodules with `git@host:` and `ssh://git@host/` URLs on the host of the source, without a change to `.gitmodules`
