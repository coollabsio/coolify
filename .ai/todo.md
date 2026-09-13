# Make internal workload DNS names permanent

- [x] Add failing tests for first-owner collision behavior and rename stability.
- [x] Persist an immutable internal DNS label for each Node workload.
- [x] Keep the first workload on the plain slug and suffix only later collisions.
- [x] Keep the stored DNS label when the workload display name changes.
- [x] Verify movement, collision, and ownership tests.
- [x] Run formatting and focused tests.
- [x] Search GitHub issues and discussions.
- [x] Record review evidence and commit the change.

## Review

- `node_workloads.internal_dns_name` stores the permanent DNS label.
- The first assigned workload claims the plain slug. A later workload with the same slug in that mesh gets the shortest unique UUID suffix, starting at eight characters.
- Allocation uses a database transaction and row locks. Existing stored names take precedence over unallocated names.
- Renaming a workload changes only its display name. Publication continues to use its stored DNS label.
- The same plain DNS label can exist in a separate mesh because Corrosion data is mesh-local.
- Coolify tests: 18 passed, 83 assertions.
- Pint and `git diff --check` passed.
- Live test at http://localhost:8000: `web-a` kept `web-a.default.coolify.internal`; a new colliding workload received `web-a-dnscolli.default.coolify.internal` and resolved to Node B.
- Live rename test: renaming `web-b` to `web-a` kept its permanent `web-b.default.coolify.internal` record.
- Live cleanup removed the temporary workload, container row, pivot row, and suffixed DNS record. The original `web-a` and `web-b` records still resolve.
- Related: open issue https://github.com/coollabsio/coolify/issues/5685.
- Similar: closed issue https://github.com/coollabsio/coolify/issues/11142 and open discussion https://github.com/coollabsio/coolify/discussions/9377.
