# Verify all Sentinel and Flux features in development

- [x] Inventory the implemented node, Sentinel, Flux, TLS, and QEMU features.
- [x] Run the complete related automated test set.
- [x] Verify the live node, Podman validation, Sentinel service, and Flux TLS connection.
- [x] Exercise live ping and server-information requests.
- [x] Exercise live certificate renewal and trust repair.
- [x] Exercise live Sentinel stop, start, update, and reconnection.
- [x] Verify UI visibility and refresh behavior with the development page and Livewire tests.
- [x] Check service logs for errors and reconnect loops.
- [x] Fix the Podman runtime detection defect found during live testing.
- [x] Finish the remaining active node naming cleanup.
- [x] Search related GitHub issues and discussions.

## Review

- Coolify focused suite: 85 tests passed with 413 assertions.
- Sentinel full workspace: 318 tests passed; one network test remains intentionally ignored.
- The QEMU node is reachable and usable, and validation selects Podman.
- Live ping and system information succeeded through Coolify -> Flux -> Sentinel.
- Live server information now reports `podman` version `4.9.3`; testing found and fixed the previous Docker compatibility-command misidentification.
- Forced leaf-certificate renewal moved version 4 to 5, kept the same CA, served a new fingerprint, and reconnected.
- Trust repair completed and the TLS connection remained usable.
- Stopping Sentinel changed Flux state to `reconnecting`; starting it restored `connected`, and ping succeeded.
- The authenticated page at `http://localhost:8000/server/development-qemu-node-worker/sentinel` shows all development controls and TLS state.
- Flux has no errors after the final update. Sentinel has no warnings after its final connection; earlier warnings match the intentional Flux and Sentinel restarts.
- The development Compose file and UI helper now use node terminology.
- No directly related GitHub issues, pull requests, or discussions were found. Discussion #11565 is a similar word match only and concerns the unrelated Fluxer service template.
