# Node sources in cluster firewall

- [x] Reproduce the missing Node source in a failing test.
- [x] Extend firewall rules to use either a workload or a Node as the source.
- [x] Send the selected Node WireGuard IP to Sentinel.
- [x] Update the firewall UI and existing rule display.
- [x] Run focused tests, formatting, build, and browser verification.
- [ ] Commit, push, wait for builds, and update local VMs.
- [x] Record review results.

## Review

- The Source control now groups assigned Nodes and workloads.
- Node-source rules use the Node WireGuard address and permit only the selected protocol to the destination workload.
- Sentinel applies the allow rule to forwarded traffic and to locally generated traffic on the selected Node.
- Focused Coolify tests passed: 40 tests and 169 assertions.
- The production frontend build and all Sentinel workspace checks passed.
- Browser verification showed both local Nodes in the Source control.
