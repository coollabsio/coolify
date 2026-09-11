# Improve Flux mobile actions and notifications

- [x] Make the Flux action group wrap on narrow screens.
- [x] Send refresh and ping results through notifications.
- [x] Remove the inline ping response.
- [x] Run focused tests and formatting.
- [x] Verify the UI behavior.
- [x] Search related GitHub issues and discussions.
- [x] Record review results.

## Review

- The four Flux actions now wrap to additional rows on narrow screens.
- Refresh and connection-test results use the global toast notification system.
- Certificate renewal, trust repair, and errors already used notifications.
- The persistent connection state stays in the page; the transient ping callout was removed.
- Verification: 12 focused Livewire tests passed, Pint passed, and the production frontend build completed.
- Jean did not report a managed Run environment, so no browser session was available for an interactive mobile check.
- GitHub: no exact issue or discussion matched this UI change. The open v5 tracking issue #5685 is related.
