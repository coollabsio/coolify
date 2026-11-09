---
type: fixed
commit: dd8ea3dc93
---
Outline service template restarting in a loop with `syntax error at or near "TRIGGER"` on `postgres:12-alpine` ([#12345](https://github.com/coollabsio/coolify/issues/12345))

- New services use PostgreSQL 16
- An existing service keeps PostgreSQL 12, the Outline service documentation has the steps to upgrade it
