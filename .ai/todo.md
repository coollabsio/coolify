# Implement Node cluster networking

- [x] Audit the approved design, plan, current Coolify changes, Sentinel/Flux code, QEMU tools, and repository instructions.
- [x] Complete the Coolify cluster domain with TDD: schema, CIDR rules, safe allocation, policies, membership, revisions, and deletion rules.
- [x] Complete the Livewire cluster list/detail UI, navigation, Node status, recovery actions, responsive controls, and authorization tests.
- [x] Add typed versioned Sentinel/Flux network and Corrosion protocol messages, capabilities, durable handlers, and tests.
- [x] Implement safe WireGuard full-mesh staging, validation, rollback, observation, drift reporting, and Coolify orchestration.
- [x] Implement scoped nftables reconciliation, snapshots, rollback, observation, and preservation tests.
- [x] Implement hardened Corrosion management, endpoint ownership/expiry, cluster health, and internal DNS.
- [x] Extend the explicit QEMU development environment and prove the full flow on two Nodes.
- [x] Run focused PHP and Rust suites, Pint, the production frontend build, and live verification through the Jean Run environment.
- [x] Search Coolify and Sentinel issues/discussions, update architecture documentation, record evidence, and commit coherent slices.

## Review

- Coolify now owns team-scoped cluster CRUD, stable address allocation, full-mesh intent, revision and status tracking, authorization, UI controls, and SSH recovery.
- Sentinel and Flux now support typed durable WireGuard, nftables, Corrosion, inspection, and endpoint snapshot commands. Corrosion uses cluster ID `48134`, and DNS serves `*.default.coolify.internal` only on the WireGuard addresses.
- The live topology used Node A `192.168.122.50` / `10.240.0.2` and Node B `192.168.122.51` / `10.240.0.3` in cluster `aflbdwjsajunydvmymb06vxa` at revision 4.
- Both Nodes run the final Sentinel binary SHA-256 `0425be199048c199ebf05c3439d45f420e7364a5d6859a17f09ab91d2b41ea30`. Sentinel, Corrosion, and discovery DNS are active on both Nodes.
- Final images: Flux `sha256:c2a96da8411d4d07186b954f8c56b234ea96b1445e98ff6db02854c1034d3162`; Sentinel host `sha256:9505ce8df730325021f31c0d06b7ab23d166c8c12d6da4e175f9a26633bc3309`.
- `web-a` and `web-b` deploy through durable operations to separate Nodes. Both endpoint rows replicate to both Corrosion members. Each Node resolves both names and gets HTTP `200` from both local and remote workloads.
- DNS returns one A answer and an authoritative empty AAAA answer. Queries to the physical Node addresses are blocked. Stopped workloads disappear from DNS and return after a durable start action.
- Service restarts and the scoped SSH repair keep both endpoint rows, restore resolver settings, keep peer state alive, and preserve the unrelated `inet user_owned_test` table.
- An unsafe revision 1002 update returned HTTP `502`. Automatic rollback kept revision 4, restored matching active and last-good hashes, disarmed the timer, restored `systemd-resolved`, kept 0% peer packet loss, and kept DNS-name HTTP traffic at `200`.
- Final normal reconciliation completed 10 of 10 operations. Both Nodes report Corrosion `v1.0.0`, `converged`, with two endpoints.
- Coolify verification: 130 focused tests passed with 505 assertions; Pint passed; `npm run build` passed. The build has one pre-existing non-fatal Tailwind token warning.
- Sentinel verification: formatting and Clippy passed; the full Rust workspace passed 361 tests with one existing network test ignored.
- Jean reported no configured Run environment. Live HTTP verification used the existing development stack at `http://127.0.0.1:8000`; login and the authenticated cluster detail page returned HTTP `200`.

### Sentinel builds

| Commit | CI | Release |
| --- | --- | --- |
| `aa25435` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34749784945) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34749784928) |
| `0339b8b` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34750339312) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34750339685) |
| `fac229e` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34750972346) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34750972334) |
| `19cae5a` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34751674345) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34751674365) |
| `d13b5ca` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34752692023) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34752692043) |
| `a1cb4fb` | [passed](https://github.com/coollabsio/sentinel/actions/runs/34753267169) | [passed](https://github.com/coollabsio/sentinel/actions/runs/34753267123) |

### GitHub discovery

- No existing Coolify or Sentinel issue, pull request, or discussion is fully fixed by this Node-only feature.
- Related, closed: [Coolify issue #8668](https://github.com/coollabsio/coolify/issues/8668), remote application ingress routing.
- Related, closed and not merged: [Coolify pull request #8680](https://github.com/coollabsio/coolify/pull/8680), master proxy routing for remote resources.
- Similar, open: [Coolify discussion #3158](https://github.com/coollabsio/coolify/discussions/3158), internal URLs for legacy services and applications.
- Similar, open: [Coolify discussion #9377](https://github.com/coollabsio/coolify/discussions/9377), Docker Compose to standalone database DNS.
- Related, closed: [Coolify discussion #1957](https://github.com/coollabsio/coolify/discussions/1957), a general WireGuard service request.
- Related, open: [Coolify discussion #1847](https://github.com/coollabsio/coolify/discussions/1847), general VPN support.
