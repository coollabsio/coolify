# Add internal DNS name editing to the Node UI

- [x] Add failing Livewire tests for valid, invalid, and colliding DNS names.
- [x] Add an editable DNS label to each workload row.
- [x] Validate authorization, DNS label syntax, and mesh uniqueness.
- [x] Republish endpoint snapshots after a saved change.
- [x] Run focused tests, Blade validation, and the frontend build.
- [x] Verify the rendered UI behavior with Livewire tests. No Jean Run environment was available for browser automation.
- [x] Search GitHub issues and discussions.
- [x] Record review evidence and commit the change.

## Review

- Admins and owners can edit the permanent DNS label from each workload row on the Node page.
- The UI shows the fixed `.default.coolify.internal` suffix and sends one workload UUID to the save action.
- Input is normalized to lowercase and must be one valid DNS label of at most 63 characters.
- A name already used by another workload in the same mesh is rejected with an inline validation error.
- The save query is scoped to the current team and Node. Members and cross-team identifiers cannot update a workload.
- A successful save republishes owned discovery snapshots for all Nodes assigned to that workload.
- Coolify tests: 32 passed, 144 assertions.
- Pint, Blade cache validation, Blade cache clearing, Vite production build, and `git diff --check` passed.
- Vite reported the existing CSS comment parser warning; the build completed successfully.
- The development app is available at http://localhost:8000, but Jean reported no Run environment for browser automation.
- Related: open issue https://github.com/coollabsio/coolify/issues/5685.
- Similar: closed issues https://github.com/coollabsio/coolify/issues/11142 and https://github.com/coollabsio/coolify/issues/11254, plus open discussion https://github.com/coollabsio/coolify/discussions/9377.
