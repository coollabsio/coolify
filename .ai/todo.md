# Add canonical Node DNS records

- [x] Add a failing test for the canonical Node endpoint in each discovery snapshot.
- [x] Publish a stable `<node>.nodes.coolify.internal` record with the Node WireGuard address.
- [x] Show the Node record in the existing Internal DNS view.
- [x] Verify forward and reverse lookup on both Nodes, including replication and restart.
- [x] Run focused tests, Pint, Blade validation, and the frontend build.
- [x] Search related GitHub issues and discussions.
- [x] Record review and verification results.

## Review

- Each Node now adds one healthy `nodes` namespace endpoint to its existing owned Corrosion snapshot.
- The Node hostname uses the slug of the Node name and expires through the same five-minute ownership lease as workload records.
- Both Nodes resolved `qemu-worker-node-a.nodes.coolify.internal` and `qemu-worker-node-b.nodes.coolify.internal` after replication and service restarts.
- PTR responses include the canonical Node name and the workload name for the shared Node address.
- The existing Internal DNS action returned both Node records and both workload records.
- Pint, 13 focused tests with 67 assertions, Blade compilation, and the Vite production build passed.
- No exact matching GitHub issue was found. Related open issue: https://github.com/coollabsio/coolify/issues/5685. Similar open discussion: https://github.com/coollabsio/coolify/discussions/9377.
