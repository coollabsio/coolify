# Decision 0017: Roll back to an earlier revision by its image digest

**Status: Accepted**

**Date: 2026-11-11**

## Context

Workload revisions are immutable, so a rollback can redeploy an earlier
revision. The cluster registry kept a limited number of images (decision 0006)
and lost its images when it moved to another Node. Decision 0030 removed the
cluster registry; local images now stay in the Podman store of the Nodes.

## Decision

- Each revision stores the image by digest.
- Each Node keeps the local images of the deployed revision and the last 2
  earlier revisions of each workload, and a build Node keeps the last 3
  successful local builds of each workload (decision 0027). (History: the
  cluster registry kept the last 10 pushed images of each workload until
  decision 0030 removed it.)
- **Rollback** redeploys the revision that the user selects.
- When the image of the revision is gone, Coolify rebuilds the same commit (Git
  sources) or pulls the same digest (image sources). When that fails, the
  rollback fails with a clear error.

## Consequences

- The image disk use of each Node has a limit.
- A rollback to an image that no Node keeps needs a rebuild.

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
- A Git or inline Dockerfile application with a local image
  (`localhost/<workload>:<build>`): the image deploys when a Node still keeps
  it, and the deploy fetches it from that Node (decision 0030). When no Node
  keeps it, Coolify starts a build that copies the source and the commit of
  the build of that image, and the build job deploys the result like a normal
  build. Without that build or its commit, the rollback fails with an error.
- An image in an external registry is pulled by digest. A missing image fails
  the deployment with the pull error.
- (History: until decision 0030, Coolify asked the registry Node with
  `registry.inspect.v1` for the digest. That command is removed.)
- An image application keeps the tag that the user entered. Each deploy that
  pulls a newer image (a user Deploy, a stack deploy) reads the digest of the
  tag from the registry with a `HEAD` request (the registry login of the
  workload, no cache) and records it in the request of the deployment
  (`image_digest`). **Rollback** is on a deployment: a tag rolls back to
  `<repository>@<digest>` of that deployment, so a tag that was pushed again
  does not run the newer image. The rolled-back image shows the digest on the
  Configuration page. The history offers a rollback also to an earlier
  deployment of the same tag when its digest differs. A deployment without a
  known digest (before this change, or the registry did not answer) rolls back
  to the tag, which runs the image that the Node has for it. The digest is read
  just before the Node pulls, so a push in that short window can record the
  next digest. The API takes `deployment_uuid`, or `revision_uuid` for the last
  deployment of the revision with a known digest. (History: until 2026-11-27,
  Coolify did not resolve tags, because Sentinel does not report the digest
  that it pulled.)
- Coolify finds the Nodes that keep a local image from the deploys and the
  keep list of the image cleanup (decision 0027). It does not ask Podman.
- A database cannot be rolled back: an older engine can fail to read the data
  that a newer one wrote. Restore a backup instead.
- The registry default and existing registries with the old default of 5 keep
  10 images.
