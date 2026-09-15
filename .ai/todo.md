# Show core cluster firewall rules

- [x] Add a failing Livewire test for the system-managed rules.
- [x] Add the read-only core traffic section to the cluster firewall UI.
- [x] Run formatting and focused tests.
- [x] Verify the section in the running UI.
- [x] Commit and push the changes.
- [x] Record review results.

## Review

- The Firewall panel shows six read-only cards for WireGuard, Corrosion gossip, the local Corrosion API, workload DNS, established connections, and the default deny policy.
- The WireGuard card uses the cluster's configured UDP port.
- The cards use the existing firewall row size, spacing, typography, and neutral system-managed badge.
- The browser check found that the deny policy blocked the local Corrosion API. Sentinel now permits TCP 8080 only when the source and destination are the same local Node address.
- The Node cluster test suite passed: 37 tests and 137 assertions.
- The production frontend build passed.
- Sentinel network tests and Clippy passed.
- Sentinel CI and release passed for commit `ba3e99f`.
- Both VMs use Sentinel SHA-256 `f76f7a7a13fe0ae7cfb3011b7b2a1c49631daba96e97d52031e0220b35c79204`.
- Live endpoint reconciliation succeeded on both Nodes after the local-only rule was installed.
