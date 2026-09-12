# Add Node deployment convergence

- [x] Define convergence outcomes from a deployment revision and observed inventory.
- [x] Add failing tests for successful verification, verification failure, and uncertain recovery.
- [x] Implement convergence after deployment and before uncertain replay.
- [x] Show verification state and results in the Node UI where needed.
- [x] Update the v5 architecture brief.
- [x] Run focused tests, formatting, build, and live QEMU verification.
- [x] Search GitHub issues and discussions.

## Review

- Deployment operations now enter `verifying` after Sentinel returns. Coolify refreshes inventory and requires the requested managed revision and image to be running before success.
- A missing, stopped, or image-mismatched container fails the operation and stores the observed verification result.
- Manual recovery checks current inventory first. If the requested revision is already running, Coolify marks the operation successful without replaying the command. Otherwise, it safely replays the same command UUID.
- The live QEMU Node completed operation 9 with a converged running container. An uncertain recovery then returned to succeeded without increasing its attempt count.
- Pint passed. The focused suite passed 59 tests with 227 assertions. The Vite build passed with the existing CSS optimizer warning.
- GitHub search found no matching issue or discussion that this change fully fixes or directly relates to the Node convergence flow.
