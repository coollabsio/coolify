# Prevent workload DNS slug collisions

- [x] Add a failing test for colliding workload slugs in one Node mesh.
- [x] Add a short stable UUID suffix only when a slug collides in that mesh.
- [x] Keep unique workload DNS names unchanged.
- [x] Verify movement and DNS lifecycle tests.
- [x] Run formatting and focused tests.
- [x] Search GitHub issues and discussions.
- [x] Record review evidence and commit the change.

## Review

- Distinct workloads whose names normalize to the same DNS label get an eight-character UUID suffix.
- Collision checks include all unique workloads assigned inside the same Node cluster.
- The same slug in another mesh does not cause a suffix.
- A workload assigned to more than one Node is counted once and does not collide with itself.
- Existing unique names keep their current friendly DNS label.
- Coolify tests: 18 passed, 79 assertions.
- Pint and `git diff --check` passed.
- Live collision smoke test passed through `http://localhost:8000`: renaming `web-b` to `web-a` removed the plain name and published `web-a-scpbh67a` on `10.240.0.2` plus `web-a-fxkc4oo0` on `10.240.0.3`.
- The live test restored `web-b`; both plain names resolved again and both temporary suffixed names were withdrawn.
- Related: open issue https://github.com/coollabsio/coolify/issues/5685.
- Similar: closed issue https://github.com/coollabsio/coolify/issues/11142 and open discussion https://github.com/coollabsio/coolify/discussions/9377.
