# Flux TLS support

- [x] Define and commit the TLS architecture and implementation plan.
- [x] Pin Sentinel TLS to the installed private CA and verify DNS, IPv4, and IPv6 identities.
- [x] Make Flux fail closed when production TLS is absent or invalid.
- [x] Add the 100-year Coolify installation CA and 90-day Flux leaf lifecycle.
- [x] Add atomic materialization, scheduled renewal, rollback, and runtime ownership.
- [x] Install and repair Sentinel trust over SSH with complete rollback.
- [x] Add assignment trust metadata and connection TLS reporting.
- [x] Make the development stack generate and use TLS by default.
- [x] Add development-only TLS state, renewal, and repair controls.
- [ ] Add staged dual-CA rotation and per-server acknowledgements.
- [ ] Run the final full-suite and rotation verification pass.

## Review

- Published Sentinel and Flux `main` images start with pinned private-CA TLS.
- The local systemd testing host connected with `transport=Tls`.
- The ping passed through TLS in 12 ms before and after forced leaf renewal.
- Automated tests cover CA/key encryption, DNS/IPv4/IPv6 SANs, invalid trust, plaintext rejection, atomic files, renewal rollback, and SSH repair rollback.
- Remaining scope is staged dual-CA rotation. SSH is already available as the emergency repair path.
