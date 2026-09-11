# Fix Flux certificate renewal lock permissions

- [x] Reproduce and prove the ownership conflict with a failing test.
- [x] Keep the shared Flux PKI readable by Flux without removing Coolify write access.
- [x] Run focused tests and formatter.
- [x] Verify certificate renewal in the development environment.
- [x] Search related GitHub issues and discussions.
- [x] Record the result and test steps.

## Review

- Root cause: the root-run PKI initializer created the lock and PKI as UID 0/65532, while the UI runs PHP as UID 1000.
- Fix: the development initializer repairs the existing volume and then runs as `www-data`; Flux also runs as UID/GID 1000, so the key can remain mode `0600`.
- Regression: the v5 Compose contract test now requires the shared UID and ownership repair.
- Verification: 46 focused tests passed; a forced renewal executed as `www-data` returned `true`; Flux restarted with TLS and UID/GID 1000.
- GitHub: no exact Flux renewal permission report was found. The open v5 tracking issue #5685 is related. Other certificate-permission reports concern database TLS and are only similar.
