# Review and document the current v5 control architecture

- [x] Reconcile the active architecture documents with the implemented Sentinel and Flux slice.
- [x] Add a brief current-state architecture document.
- [x] Remove stale coold naming from active architecture references.
- [x] Check links, terminology, and repository state.
- [x] Search related GitHub issues and discussions.
- [x] Record review results.

## Review

- `docs/v5/architecture/current-state.md` is the canonical snapshot of the
  implemented Coolify, Flux, and Sentinel control path.
- The snapshot separates implemented diagnostics from planned runtime and
  scaling capabilities.
- Active architecture references now use the Sentinel name and the implemented
  internal HTTP and outbound TLS gRPC transports. Historical ADRs keep the old
  coold name for context.
- All relative links in the active architecture documents resolve, and
  `git diff --check` passes.
- GitHub issue #5685 is an open related v5 tracking issue. No matching GitHub
  discussion was found.
