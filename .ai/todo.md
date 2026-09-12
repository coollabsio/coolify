# QEMU Sentinel reconnect investigation

- [x] Reproduce the Connected at timestamp change.
- [x] Compare Flux, Coolify, and QEMU Sentinel logs.
- [x] Find and test the root cause.
- [x] Add a regression test and implement the smallest fix.
- [x] Verify that the connection stays stable.

## Review

- Sentinel intentionally replaces its stream every 14 minutes because its Flux credential lasts 15 minutes and refresh starts one minute early.
- Coolify removed the connection cache entry during the short replacement, which caused the UI flicker and reset `connected_at`.
- Coolify now keeps a 15-second `reconnecting` state and preserves the logical connection start when the new stream arrives.
- A real QEMU Sentinel restart changed the Flux connection ID but preserved `connected_at`.
- Focused tests passed: 33 tests and 169 assertions.
