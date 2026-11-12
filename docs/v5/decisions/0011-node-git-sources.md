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

## Consequences

- Users keep their existing GitHub Apps and deploy keys.
- A builder Node sees a credential only during a build.
- A Gitea or GitLab token has no 1-hour form, so the builder sees the
  configured token for the build time.

## Not decided here

- Preview deployments for applications with volumes.
- Git LFS and submodules.
