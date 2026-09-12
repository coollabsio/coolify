# Optional KVM v5 worker

- [x] Review the current development host, v5 Compose override, and server seeding.
- [x] Select the first KVM worker design.
- [x] Write the design specification.
- [x] Get user approval for the written specification.
- [x] Write the implementation plan.
- [x] Implement and verify the optional worker.

## Current decision

- Keep the Docker testing host for fast control-channel tests.
- Add an optional QEMU/KVM worker for Podman and host-level integration tests.
- Use QEMU user networking with fixed host port forwarding. This avoids host
  bridge and libvirt network changes.
- Do not add WireGuard, Corrosion, or firewall tests in the first slice.

## Review

- Reused the existing libvirt QEMU manager instead of adding a second VM
  implementation.
- Added the `v5-worker` Ubuntu profile at `192.168.122.50` with Podman and its
  Docker-compatible API socket.
- The QEMU host setup forwards the loopback-bound development dashboard through
  the libvirt gateway. The guest maps `coolify-flux` to the same gateway.
- The QEMU command now waits for cloud-init before it reports the VM as ready.
- The Sentinel installer extracts its binary with Docker or Podman and no longer
  depends on `docker.service`.
- A valid Sentinel can request a new Flux assignment while Coolify temporarily
  marks its server unreachable. This lets the control channel recover after a
  reboot.
- Focused tests passed: 36 tests and 158 assertions. Pint and `git diff --check`
  passed.
- The real VM passed SSH, systemd, Podman, Sentinel installation, TLS Flux
  connection, and guest reboot recovery checks.
- GitHub issues #2720 and #310 are closed similar Podman requests. Issue #4451
  is a closed related KVM report. No matching discussion or fully fixed issue
  was found.
