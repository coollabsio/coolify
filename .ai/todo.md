# Show nodes in the Servers view

- [x] Add team-scoped nodes to the Servers index component.
- [x] Render nodes beside legacy servers in the Servers view.
- [x] Mark each node role and status, and link nodes to their node page.
- [x] Add Livewire regression tests for visibility and team isolation.
- [x] Format, build, and verify the development page.
- [x] Search related GitHub issues and discussions.

## Review

- Added a development-gated Nodes section to the existing Servers view.
- The QEMU worker shows its name, description, role, and validation status.
- Each node links to its host Sentinel and Flux page.
- Node queries are scoped to the current team. Legacy server rendering is unchanged.
- Five focused tests passed with 16 assertions. Blade compilation and the frontend build passed.
- No related GitHub issues or discussions were found.
