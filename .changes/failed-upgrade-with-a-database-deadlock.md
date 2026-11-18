---
type: fixed
---
Failed upgrade with a database deadlock when deployments or jobs ran during the database migration

- The migration of image scanning no longer upgrades a table lock, and the migration step tries again after a deadlock
