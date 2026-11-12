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
