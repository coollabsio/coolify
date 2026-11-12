# Decision 0012: Pass build-time secrets as BuildKit secret mounts only

**Status: Accepted**

**Date: 2026-11-11**

## Context

v4 passes build-time variables as Docker build args. A build arg can stay in
the image history and metadata. Cluster applications push their images to the
cluster registry (decision 0006) and, later, to external registries, so a leaked
value persists there. Decisions 0006 and 0008 leave build-time secrets open.

## Decision

- A build-time variable that is not secret is a plain build arg, as in v4.
- A build-time variable that is secret goes to the build only as a BuildKit
  secret mount (`--secret id=<KEY>`). A Dockerfile reads it with
  `RUN --mount=type=secret,id=<KEY>`. Railpack builds use its secret support.
- A secret is never a build arg.
- `image.build` carries the secret values. Sentinel does not journal them and
  does not write them to disk outside the build.
- The UI tells the user that a Dockerfile must read a secret with a secret
  mount.

## Consequences

- No secret is in an image or in its history.
- A Dockerfile that reads a secret through `ARG` must change.

## Not decided here

- Secret manager references for build-time secrets.
