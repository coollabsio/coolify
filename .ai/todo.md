# Add the Node internal DNS view

- [x] Add tests for Corrosion row parsing, authorization, route visibility, and refresh behavior.
- [x] Add a Node-scoped action that reads discovery endpoints from Corrosion.
- [x] Add an Internal DNS page to the existing Node side menu.
- [x] Show hostname, address, owner Node, runtime status, and expiry with copy controls.
- [x] Add a refresh action and clear error state.
- [x] Run focused tests, Pint, Blade validation, frontend build, and live data verification.
- [x] Search related GitHub issues and discussions.
- [x] Record review and verification results.

## Review

- The page reuses the existing Node settings workspace and side menu.
- Reads are scoped to a current-team Node and authorized with the Node view policy.
- Corrosion output is validated before it reaches Livewire state; SSH errors use safe UI text.
- The live development cluster returned both `web-a` and `web-b` records through the new action.
- Pint, 44 focused tests with 109 assertions, Blade cache validation, and the Vite production build passed.
- Jean reported no configured Run environment, so no browser session was available for live visual verification.
- No exact GitHub issue matches were found. Related open issue: https://github.com/coollabsio/coolify/issues/5685. Similar open discussion: https://github.com/coollabsio/coolify/discussions/9377.
