# Deny non-core Node mesh traffic

- [x] Add failing Sentinel tests for the Node-to-Node allow list.
- [x] Permit only Corrosion gossip between mesh Node addresses.
- [x] Keep WireGuard transport, established replies, and workload DNS working.
- [x] Update the firewall helper text.
- [x] Run focused and full verification.
- [x] Publish Sentinel and update the local Flux and VM binaries.
- [x] Verify the live Node-to-Node policy.
- [x] Record review results.

## Review

- Node-to-Node mesh traffic is denied in both input and output directions.
- Corrosion gossip remains allowed on UDP port 8787.
- WireGuard transport remains allowed on the configured physical UDP port, and established replies remain allowed.
- Workload DNS rules and explicit workload firewall rules are unchanged.
- Coolify tests passed: 40 tests and 170 assertions.
- Sentinel formatting, Clippy, the full workspace test suite, and focused network tests passed.
- Sentinel CI and release passed for commit `fc7f9cf`.
- Both VMs use Sentinel SHA-256 `396150a0020e3ad000b38da6efa68bec0e2bc6e179bebe049121f7298d7a18d2`.
- Live checks showed an active cluster, current WireGuard handshakes, successful Corrosion inspections, and blocked Node-to-Node ICMP.
