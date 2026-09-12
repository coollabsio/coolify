# Separate legacy and native v5 Sentinel paths

- [x] Find every host-native Sentinel and Flux entry point.
- [x] Add failing tests for legacy-server denial and v5 access.
- [x] Centralize the native-v5 eligibility rule on Server.
- [x] Enforce the rule in APIs, actions, jobs, and Livewire methods.
- [x] Hide host-native Sentinel and Flux UI from legacy servers.
- [x] Verify legacy and v5 behavior.

## Review

- `Server::isNativeV5()` is the central boundary for host-native Sentinel and Flux.
- Legacy servers cannot receive Flux assignments, report Flux connection events, or run Flux commands.
- Host Sentinel install, trust repair, state, and controls are unavailable on legacy servers.
- Native v5 servers cannot start or stop the legacy container Sentinel path.
- Server information uses Flux only for native v5 servers and keeps SSH for legacy servers.
- Focused tests passed: 84 tests and 426 assertions.
