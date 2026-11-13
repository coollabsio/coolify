# Decision 0017: Roll back to an earlier revision by its image digest

**Status: Accepted**

**Date: 2026-11-11**

## Context

Workload revisions are immutable, so a rollback can redeploy an earlier
revision. The cluster registry keeps a limited number of images (decision 0006)
and loses its images when it moves to another Node.

## Decision

- Each revision stores the image by digest.
- The cluster registry keeps the last 10 pushed images of each workload. This
  replaces the value of 5 in decision 0006.
- **Rollback** redeploys the revision that the user selects.
- When the image of the revision is gone, Coolify rebuilds the same commit (Git
  sources) or pulls the same digest (image sources). When that fails, the
  rollback fails with a clear error.

## Consequences

- The registry disk use has a limit.
- A rollback after a registry move can need a rebuild.

## Not decided here

- Rollback of volume data.

## Implementation

- **Rollback** on an earlier successful deployment in the deployment history
  (`RollbackNodeWorkload`) adds a new latest revision with the image of the
  selected revision. History stays linear: the latest revision is what runs,
  and the reconciler, moves, and recovery deploy it. The deploy is the normal
  path (`CreateDeploymentOperation`, `DeployNodeWorkloadJob`), so rolling
  deploys (decision 0022) apply. The pull policy is `missing`: the image is a
  digest, or the tag that the earlier revision ran.
- Only the image rolls back. Environment variables, secrets, the start
  command, resource limits, volumes, and the health check keep their current
  values, from the workload as for a Deploy. The settings pages then show what
  runs, and the next Deploy does not undo the rollback silently. Old values of
  a rotated secret are never deployed again. For an image application, the
  image on the Configuration page is the rolled-back image.
- A Git or inline Dockerfile application: Coolify asks the registry Node
  (`registry.inspect.v1`) for the digest. When the registry does not have it,
  Coolify starts a build that copies the source and the commit of the build
  with that digest, and the build job deploys the result like a normal build.
  Without that build or its commit, the rollback fails with an error. When the
  registry cannot answer (offline, older Sentinel, truncated list), Coolify
  deploys, and a missing image fails the deployment with the pull error.
- An image application keeps the tag that the user entered. Coolify does not
  resolve it to a digest at deploy time: the Coolify container has no access
  to the registries of the Nodes, and Sentinel does not report the repository
  digest that it pulled. A rollback to a tag that was pushed again runs the
  build that the Node has for the tag, or pulls the current one.
- A single-Node cluster keeps its builds as `localhost/` tags in Podman. Coolify
  cannot check them, so a pruned local image fails the deployment.
- A database cannot be rolled back: an older engine can fail to read the data
  that a newer one wrote. Restore a backup instead.
- The registry default and existing registries with the old default of 5 keep
  10 images.
