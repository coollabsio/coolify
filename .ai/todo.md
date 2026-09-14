# Explicit ICMP firewall rules

- [x] Reproduce the rejected ICMP rule in Coolify and Sentinel tests.
- [x] Add ICMP to the workload firewall UI without a port.
- [x] Render only explicit ICMP allow rules in Sentinel.
- [x] Remove all default mesh ICMP allow rules.
- [x] Run focused tests, formatting, build, and browser checks.
- [x] Record the review and test results.

## Review

- ICMP is denied when no explicit workload firewall rule exists.
- The firewall form now offers ICMP and hides the port field for that protocol.
- Coolify stores an ICMP rule with port `0`; Sentinel renders it as an ICMP protocol rule without a port.
- Coolify passed 35 tests with 118 assertions. Sentinel passed all 62 control tests.
- The production asset build passed. Browser testing confirmed the ICMP option and the hidden port field at `http://127.0.0.1:8000/node-clusters/8g63bbnkqclq7yztfrcabznm`.
- Both QEMU Nodes applied revision 6. With no rules, Node A could not ping workload `100.64.1.2`.
