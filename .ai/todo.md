# Managed Node workload ingress firewall

- [x] Specify ingress trust boundaries from the current deployment and proxy paths.
- [x] Add failing Coolify tests for persisted ingress rules, authorization, validation, and Sentinel payloads.
- [x] Add failing Sentinel tests for default-deny ingress and narrow allow rules.
- [x] Implement the smallest compatible Coolify data model, reconciliation contract, and Firewall UI.
- [x] Implement Sentinel nftables ingress enforcement with safe reconcile and rollback behavior.
- [x] Run focused and full checks in both repositories.
- [x] Commit and push both repositories, watch CI and release builds, and deploy published images.
- [x] Test unmanaged-container, Node/LAN, proxy/published-port, mesh, DNS, and outbound behavior live.
- [x] Search related GitHub issues and discussions and record review evidence.

## Design

- Treat traffic to managed workload addresses from outside managed workload CIDRs as ingress.
- Deny this forwarded traffic by default. Keep established flows, WireGuard routing, internal DNS, and workload outbound traffic.
- Store directional ingress rules by destination workload, protocol, and destination port. A rule permits any non-mesh source to that one workload port.
- Keep east-west workload rules separate. A workload source still requires an east-west rule and cannot use an ingress rule.
- Show ingress rules in the existing cluster Firewall view and use the existing cluster update authorization.
- Reuse the current revision, last-known-good snapshot, validation, and rollback mechanisms.

## Review

- The existing Firewall view now separates east-west workload rules from ingress rules. An ingress rule selects one destination workload, TCP or UDP, and one destination port.
- Coolify persists ingress rules per cluster, expands them to all current workload addresses, increments the network revision, and queues reconciliation after add or removal.
- Coolify rejects foreign-workload rules, denies member mutations, and rejects Sentinel results that do not confirm `ingress_enforced=true`.
- Sentinel validates typed ingress rules and renders narrow nftables exceptions before default drops in the forward and output hooks. Mesh sources cannot use ingress exceptions.
- Without an ingress rule, both a Node process and an unmanaged Podman container timed out when connecting to `100.64.0.2:80`. With the rule, both connected. After removal, access timed out again.
- The existing mesh policy remained directional: workload B could not use the ingress rule to reach workload A. Reverse DNS and outbound internet access continued to work.
- Coolify focused verification passed: 51 tests and 184 assertions, Pint, Blade compilation, Vite production build, and `git diff --check`.
- Sentinel verification passed: formatting, Clippy with warnings denied, all workspace tests, local host and Flux image builds, and `git diff --check`.
- Jean reported no registered Run environment. Live testing used the existing development stack at http://localhost:8000 and QEMU Nodes `192.168.122.50` and `192.168.122.51`.
- GitHub issue and pull-request searches found no matching item in `coollabsio/coolify` or `coollabsio/sentinel`. Discussion search was unavailable through the current GitHub CLI search.
