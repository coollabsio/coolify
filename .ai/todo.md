# Add scheduled Node inventory and workload state

- [x] Define workload state from the desired revision and observed containers.
- [x] Add a unique queued inventory refresh job per Node.
- [x] Schedule connected Nodes in chunks with distributed delays.
- [x] Show current workload state on the Node page.
- [x] Keep scheduling independent from the development environment gate.
- [x] Add tests for state rules, eligibility, scheduling, and queue behavior.
- [x] Run formatting, tests, build, and live development verification.
- [x] Update architecture notes and search GitHub issues and discussions.

## Review

- Coolify passed 62 focused tests with 200 assertions and Pint.
- The Vite production build completed. It reports the existing CSS optimizer warning.
- The Laravel schedule lists `RefreshConnectedNodesJob` every minute.
- The live QEMU Node refreshed through Flux and Sentinel. Coolify stored the snapshot time and calculated the workload as `running` from two observed containers.
- The schedule does not check `SENTINEL_HOST_ENABLED`; usable Node records and recent Flux heartbeats select work.
- GitHub search found no fully fixed item. Similar reports are open #11539, open #7287, and closed #8803; they concern the legacy Docker status path.
