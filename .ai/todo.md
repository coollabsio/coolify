# Enforce the workload firewall on the same Node

- [x] Add failing Sentinel tests for same-bridge workload isolation.
- [x] Add the bridge-family policy to Sentinel firewall reconciliation.
- [x] Run Sentinel formatting, tests, and lint checks.
- [x] Commit and push Sentinel, then wait for the image build.
- [x] Update Sentinel and Flux on both local KVM Nodes.
- [x] Smoke-test default deny and an explicit allow rule between same-Node containers.
- [x] Record review results.

## Review

- Sentinel now owns an atomic `bridge` nftables table in addition to its routed `inet` table.
- The bridge table allows established traffic and explicit workload-source rules, then denies all other workload-to-workload traffic on the same Node.
- Node-source rules stay in the routed table and are not copied into the bridge table.
- Rollback snapshots, activation checks, and cleanup include both owned tables without changing unrelated nftables rules.
- The full Sentinel workspace tests and Clippy passed.
- Sentinel CI and the multi-architecture release passed for commit `08951c3`.
- Flux and Sentinel were updated in the local development stack and on both KVM Nodes.
- Both KVM Nodes run active Sentinel services with SHA-256 `11016ff37f741875fa7aba3ef81a775b7d3fd7ac8be6bf0905e67ade74631ba1`.
- A live same-Node test confirmed default deny, TCP 8080 access after an explicit allow rule, and default deny again after rule removal.
- All temporary workloads, firewall records, and containers were removed after the smoke test.
