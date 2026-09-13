# Match the Node submenu to Server views

- [x] Add a failing UI contract test for the grouped Node side submenu.
- [x] Replace the horizontal Node menu with the Server settings workspace pattern.
- [x] Use the same Node side submenu on Overview and Terminal pages.
- [x] Run focused tests, Pint, the frontend build, and live browser checks.
- [x] Search related GitHub issues and discussions.
- [x] Record verification and review results.

## Review

- Node Overview and Terminal now use the same grouped `application-settings-navigation` side submenu and 210-pixel settings workspace as Server pages.
- The submenu has **Settings / General** and **Operations / Terminal** groups. The active state follows the current page. Terminal stays outside Livewire navigation and remains limited to team administrators and owners.
- The Node name and readiness status now use the desktop topbar context. A mobile heading remains available below the topbar breakpoint.
- The UI contract test failed before the Node sidebar existed and passed after the change.
- Pint passed. The focused suite passed 94 tests with 524 assertions. `npm run build` passed with one existing non-fatal Tailwind token warning.
- Jean reported no configured Run environment. Live browser verification used the existing development app at `http://127.0.0.1:8000`. It showed the Server-style submenu on Node Overview and Terminal, kept the correct active state, completed a real Node terminal command, and reported no browser errors.
- Review found no remaining Critical or Important findings.

### GitHub discovery

- No matching Coolify issue, pull request, or discussion was found for a Node or Server-style submenu. No existing item is fully fixed, related, or similar enough to list.
