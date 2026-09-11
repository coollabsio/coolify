# Document Sentinel log transport

- [x] Review the active v5 decision format.
- [x] Record the accepted on-demand Sentinel log design.
- [x] Add the decision to the active decision index.
- [x] Check the document for ambiguity and formatting problems.
- [x] Search related GitHub issues and discussions.
- [x] Record review results.

## Review

- Decision 0003 makes the structured in-memory Sentinel buffer and on-demand Flux request the normal v5 path.
- SSH plus bounded `journalctl` output remains the fallback when Sentinel or Flux is unavailable.
- The decision rejects continuous central ingestion by default and requires redaction before buffering.
- The exact protocol names, limits, UI, and optional external export remain open for the later implementation slice.
- `git diff --check` passes for the decision files.
- Open issue #5685 is related because it tracks Coolify v5. Closed issue #7420 is similar because it shows why startup failures need the journald fallback. This documentation change does not fix either issue.
