# Decision 0011: Build private Git repositories with v4 sources and short-lived credentials

**Status: Accepted**

**Date: 2026-11-11**

## Context

Decision 0006 builds only public Git repositories and leaves private
repositories, webhooks, and preview builds open. v4 already has team-owned Git
sources (GitHub Apps, GitLab, Gitea, deploy keys) and webhook endpoints in
`routes/webhooks.php`. Builder Nodes are workers. They must not store long-lived
Git credentials.

## Decision

- Cluster applications use the v4 Git sources of the team. There is no separate
  v5 Git integration.
- Coolify resolves the commit. For each build, it sends a short-lived
  credential to the builder with `image.build`: a GitHub App installation token
  (valid for 1 hour), or a deploy key.
- Sentinel keeps the credential in memory or on tmpfs only, deletes it when the
  build ends, and does not journal it (the journal stores a digest, decision
  0008).
- The v4 webhook endpoints also start builds of cluster applications. The
  webhook secret checks are the v4 ones.
- Preview deployments are part of this decision, but ship after private
  repositories and webhooks. Each pull request becomes a separate workload with
  a generated domain. Coolify removes it when the pull request closes.

## Amendment (2026-11-16): Auto deploy and pushes during a build

The **Webhooks** tab of a Git cluster application has the three v4 settings of
**Advanced** > **Deployment**, with the v4 defaults
(`node_workloads.is_auto_deploy_enabled`,
`is_auto_cancel_queued_deployments_enabled`,
`is_auto_cancel_building_deployments_enabled`). A stack service has no such
settings; its webhook deploys the stack.

- **Auto deploy** off: a push webhook answers "Deployments disabled.", as in v4.
- A push that comes while a build of the application runs adds a waiting build
  (`node_builds.is_waiting`), shown as Queued. When a build ends, fails, or is
  cancelled, `StartWaitingNodeBuild` starts the oldest waiting build, if no
  other build of the application is active. A push of a commit that is already
  queued or running is skipped.
- **Queued webhook deployments** (default: cancel): a newer push cancels the
  older waiting webhook builds ("Cancelled by a newer push."). "Deploy every
  push" builds each push in order.
- **Building webhook deployments** (default: let it finish): "Stop the build"
  also cancels the running webhook build, and the new build starts at once. It
  turns on the cancel of waiting builds too, as in v4.
- Only builds that a webhook started (`node_builds.is_webhook`) are cancelled.
  Manual, API, and rollback builds are never cancelled. A manual deploy while a
  build is queued or running returns that build.
- Verified on dev: each rule above, with builds in order 81, 82, 83 for
  "Deploy every push".

## Consequences

- Users keep their existing GitHub Apps and deploy keys.
- A builder Node sees a credential only during a build.
- A Gitea or GitLab token has no 1-hour form, so the builder sees the
  configured token for the build time.

## Not decided here

- Preview deployments for applications with volumes.
- Git LFS and submodules.
