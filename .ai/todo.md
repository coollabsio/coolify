# Optional KVM v5 worker

- [x] Review the current development host, v5 Compose override, and server seeding.
- [x] Select the first KVM worker design.
- [x] Write the design specification.
- [ ] Get user approval for the written specification.
- [ ] Write the implementation plan.
- [ ] Implement and verify the optional worker.

## Current decision

- Keep the Docker testing host for fast control-channel tests.
- Add an optional QEMU/KVM worker for Podman and host-level integration tests.
- Use QEMU user networking with fixed host port forwarding. This avoids host
  bridge and libvirt network changes.
- Do not add WireGuard, Corrosion, or firewall tests in the first slice.
