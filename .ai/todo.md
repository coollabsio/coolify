# Add reverse DNS for Node workload discovery

- [x] Verify current DNS and systemd-resolved behavior from official sources and the running cluster.
- [x] Add failing Sentinel tests for IPv4 PTR parsing, Corrosion lookup, PTR responses, and reverse-zone routing.
- [x] Implement authoritative PTR answers for active workload endpoints.
- [x] Route the cluster reverse zone through the Node discovery DNS service, including SSH repair.
- [x] Run Rust and PHP tests, formatting, linting, and live two-Node DNS checks.
- [x] Search related GitHub issues and discussions.
- [x] Record verification and review results.

## Review

- Sentinel now accepts IPv4 PTR questions and returns every active, healthy, non-expired workload name for the address from Corrosion.
- Oversized PTR record sets set the DNS truncation flag and can return the complete record set through DNS over TCP.
- Sentinel reconciliation and Coolify SSH repair route only the exact Node reverse names through `coolify0`, including clusters that do not use a `/24` CIDR.
- `cargo test --workspace`, Clippy with warnings denied, the locked release build, Pint, and 29 focused PHP tests passed.
- Live UDP and TCP checks on both Nodes resolved `10.240.0.2` to `web-a.default.coolify.internal` and `10.240.0.3` to `web-b.default.coolify.internal`; exact reverse routes and forward lookups also passed.
- GitHub discovery found one related open discussion: https://github.com/coollabsio/coolify/discussions/9377. No matching Sentinel issues were found.
