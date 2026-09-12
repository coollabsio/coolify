# Fix Node workload redeployment

- [x] Reproduce the permanent revision lock in a Livewire test.
- [x] Allow a new operation after the prior attempt reaches a final state.
- [x] Reuse an existing active operation to prevent concurrent duplicate work.
- [x] Update the Node UI notification and tests.
- [x] Verify the live deployment flow and update documentation.
- [x] Search GitHub issues and discussions.

## Review

- The fixed idempotency key was the root cause. It permanently mapped one Node revision to one operation.
- Each deployment attempt now gets a new idempotency key after the prior operation reaches a final state.
- Queued, dispatched, running, and uncertain operations still block a concurrent duplicate deployment.
- The live QEMU Node redeployed the same revision as operation 7. Sentinel returned success, Podman returned a new runtime ID, and Coolify calculated the workload state as running.
- Pint passed. The focused suite passed 56 tests with 216 assertions. The Vite build passed with the existing CSS optimizer warning.
- GitHub search found no issue or discussion that this change fully fixes. Open discussion #9779 is related to redeploy UI behavior, but it concerns the legacy v4 banner.
