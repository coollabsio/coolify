# Default-deny Node workload firewall

- [x] Add stable workload network and container address data.
- [x] Extend the Coolify-to-Sentinel protocol for workload networking and typed firewall rules.
- [x] Create and use managed Podman workload networks on each Node.
- [x] Enforce default-deny east-west traffic while allowing outbound internet and required mesh services.
- [x] Add authorized workload-to-workload firewall rule management in the Node cluster UI.
- [x] Reconcile rule changes safely and preserve rollback behavior.
- [x] Run focused PHP and Rust tests, formatting, Blade validation, and frontend build.
- [x] Run live network verification against the development environment.
- [x] Search GitHub issues and discussions.
- [x] Record review evidence and commit all repository changes.

## Review

- Each Node now receives a globally unique `100.64.0.0/10` workload `/24`. Each workload assignment receives a stable address from that subnet.
- Clustered deployments use a Node-owned Podman network and the assigned container IP. Sentinel rejects a pre-existing managed network when its subnet is different.
- WireGuard peers advertise both the Node control `/32` and the Node workload `/24`.
- Sentinel accepts typed source, destination, protocol, and port rules only. It rejects addresses outside managed workload subnets.
- The owned nftables table blocks workload-to-workload and workload-to-Node traffic by default. It keeps outbound internet traffic open, accepts established traffic, and permits the WireGuard and internal DNS infrastructure.
- Bridge netfilter is enabled so the same policy applies to workloads on one Node. Firewall activation still uses validation, a last-known-good snapshot, and the timed rollback path.
- The cluster Firewall UI creates directional TCP or UDP allow rules and queues reconciliation. Team scope and update authorization are enforced on the server.
- Live verification used the running development stack at http://localhost:8000 after Jean reported no registered Run environment. Two QEMU Nodes used `100.64.0.2` and `100.64.1.2`.
- Live default-deny test: workload A to workload B TCP/80 timed out. Live outbound test: workload A downloaded `http://example.com` successfully.
- Live allow test: after adding A → B TCP/80, workload A received the Nginx page from workload B. After rule removal and reconciliation, the request timed out again.
- Live DNS test: `web.default.coolify.internal` resolved to the routed container address `100.64.0.2` from both Nodes. Direct PTR lookup against the Node DNS server returned `web.default.coolify.internal`.
- Coolify focused tests: 80 passed, 304 assertions. Sentinel workspace tests passed: 62 control tests plus all other workspace tests; one existing network test stayed ignored.
- Pint, `git diff --check`, Blade compilation, and the Vite production build passed. Vite reported the existing CSS comment parser warning.
- GitHub search found no matching issue or discussion for this firewall feature.
