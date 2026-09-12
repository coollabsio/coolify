# Consolidate branch migrations

- [x] Identify the dependent migrations added by the node-mode work.
- [x] Keep the final node schema in the original server-mode migration.
- [x] Remove the unreleased corrective rename migration.
- [x] Remove the obsolete migration-specific test.
- [x] Clean the local development migration ledger.
- [x] Run focused tests and formatting.
- [x] Review GitHub issues and discussions.

## Review

- The node-mode schema now has one migration: `2026_09_12_085046_add_mode_to_servers_table.php`.
- New installations get the final `legacy` default and use the final node enum names in application code.
- The corrective `rename_server_modes_to_node_modes` migration and its compatibility test were removed because this branch is not released.
- The two Flux PKI migrations remain separate because they create two distinct tables with a foreign-key dependency; they are not corrective migrations.
- The local development migration ledger was cleaned without changing server rows.
- Focused verification passed: 76 tests and 365 assertions.
