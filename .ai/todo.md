# Add Node workload lifecycle commands

- [x] Define durable start, stop, restart, and remove command contracts.
- [x] Add failing Coolify tests for operation creation, dispatch, convergence, recovery, and authorization.
- [x] Add failing Sentinel tests for validation, Podman execution, and durable replay.
- [x] Implement typed Sentinel and Flux lifecycle command routing.
- [x] Implement Coolify lifecycle jobs and Node UI actions.
- [x] Update v5 architecture documentation.
- [x] Run formatting, focused tests, builds, and live QEMU verification.
- [x] Search GitHub issues and discussions.

## Review

- Sentinel and Flux now support the typed `workload.lifecycle.v1` command with start, stop, restart, and remove actions. Sentinel commit `4823680` added the feature, and `55e0da9` fixed workspace formatting. Both commits are on `main`.
- Coolify stores each action as a durable operation, serializes mutations per workload, and verifies the resulting Podman inventory. Manual uncertain recovery checks observed state before replay.
- The Node UI shows state-appropriate lifecycle actions and identifies lifecycle operations by action.
- Live QEMU tests passed for stop to `exited`, start to `running`, restart to `running`, and remove to `missing`. A final deployment restored the test workload to `running`.
- Live testing found that systemd killed Podman `conmon` processes during a Sentinel update. `KillMode=process` now keeps workloads independent from the Sentinel service lifecycle.
- Sentinel CI and the `main` image release passed. The local Sentinel workspace test suite and release build passed.
- Coolify Pint passed, 84 focused tests passed with 319 assertions, and the Vite build passed with the existing CSS optimizer warning.
- No GitHub item is fully fixed. Open discussion #3560 is related because it asks for container restart without a rebuild on the legacy path.
