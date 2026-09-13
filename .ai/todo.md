# Automate DNS lifecycle during workload operations

- [x] Add tests for deployment, stop, restart, removal, and move handover ordering.
- [x] Publish DNS after deployment reaches its ready running state.
- [x] Withdraw DNS immediately after stop or removal convergence.
- [x] Republish DNS after start or restart convergence.
- [x] Add a safe workload move action with publish-before-withdraw handover.
- [x] Add the move control to the Node UI with authorization and validation.
- [x] Run focused tests, formatting, Blade validation, and frontend build.
- [x] Run a live two-Node lifecycle verification.
- [x] Search GitHub issues and discussions.
- [x] Record review evidence and commit the change.

## Review

- Existing inventory reconciliation now has explicit regression coverage for start, restart, stop, and removal DNS updates.
- A move creates a durable parent operation, assigns and deploys the target, verifies a running healthy/unknown-health container, publishes the target endpoint, removes the source, withdraws its endpoint, and then detaches the source assignment.
- If target deployment or health validation fails, the source stays assigned and active. A newly added target assignment is removed when deployment did not succeed.
- Node-bound published ports are rewritten to the target Node WireGuard IP during deployment, so revisions can move between Nodes.
- The Node UI lists other Nodes from the same mesh and queues the move. Team and mesh scope checks run on the server.
- Live test at http://localhost:8000 moved `web.default.coolify.internal` from `10.240.0.2` to `10.240.0.3`, then back to `10.240.0.2`.
- The first live attempt found a real target port collision. The move failed without removing the source. After the competing test workload was removed, both moves succeeded. The original `web-a` and `web-b` workloads and DNS records were restored.
- Final live state: `web.default.coolify.internal` resolves to `10.240.0.2`; `web-b.default.coolify.internal` resolves to `10.240.0.3`; both containers are running on their original Nodes.
- Coolify tests: 52 passed, 206 assertions.
- Pint, Blade cache validation, Blade cache clearing, Vite production build, and `git diff --check` passed.
- Vite reported the existing CSS comment parser warning; the build completed successfully.
- Related: open issues https://github.com/coollabsio/coolify/issues/5685 and https://github.com/coollabsio/coolify/issues/8627.
- Similar: open issue https://github.com/coollabsio/coolify/issues/4448, open discussion https://github.com/coollabsio/coolify/discussions/9377, and closed discussion https://github.com/coollabsio/coolify/discussions/11150.
