# Add Node workload ownership foundation

- [x] Add the installation identity and workload, revision, assignment, and container schemas.
- [x] Add models, factories, enums, and relationships.
- [x] Add the standard Node container label contract.
- [x] Reconcile observed containers and classify them as managed, external, or unrecognized.
- [x] Add authorization and regression tests for ownership and team boundaries.
- [x] Document the implemented identity model.
- [x] Format and run focused tests.
- [x] Search related GitHub issues and discussions.

## Review

- Added a stable per-installation UUID and the Node workload, immutable revision, Node assignment, and observed container data model.
- Added the standard `coolify.instance`, `coolify.workload`, `coolify.revision`, and `coolify.component` label builder.
- Added transactional snapshot reconciliation with strict validation and managed, external, and unrecognized classifications.
- Ownership resolution verifies the installation, team, assigned Node, workload, and revision. A claimed `coolify.managed=true` label is not trusted by itself.
- Added workload and observed-container policies. Observed containers are read-only through authorization.
- Applied the migrations to the development database and generated its stable installation UUID.
- The focused regression suite passed: 174 tests with 653 assertions.
- Related GitHub search result: closed issue #8822 concerns legacy Docker cleanup handling of `coolify.managed`; this change does not fix it. Similar closed issues #11265 and #1737 concern legacy label management and custom Compose labels. No matching discussion was found.
