# Verify DNS lifecycle during workload movement

- [x] Add a lifecycle test for a workload move from Node A to Node B.
- [x] Verify that Node A withdraws its old workload DNS endpoint.
- [x] Verify that Node B publishes the new workload DNS endpoint.
- [x] Verify stopped, removed, expired, and recovered endpoint behavior.
- [x] Check for lifecycle gaps. No production change was required.
- [x] Run focused tests and formatting checks.
- [x] Search GitHub issues and discussions.
- [x] Record the final review and test evidence.

## Review

- The movement test starts the workload on Node A, changes its assignment to Node B, and refreshes both inventories.
- Node A publishes an owned snapshot without the workload. Its old container becomes unrecognized.
- Node B publishes the workload with its WireGuard address.
- A stopped workload stays in Corrosion with `state=stopped`; DNS excludes it.
- A removed workload is absent from the next owned snapshot.
- A recovered workload is published again with a new 300-second expiry.
- Coolify tests: 17 passed, 77 assertions.
- Sentinel DNS tests: 9 passed. Corrosion owned-snapshot tests: 3 passed.
- No Jean Run environment was available for a live move test.
- Related: open issue https://github.com/coollabsio/coolify/issues/5685.
- Similar: open discussion https://github.com/coollabsio/coolify/discussions/9377.
