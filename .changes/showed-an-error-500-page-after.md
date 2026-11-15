---
type: fixed
---
Showed an error 500 page after an upgrade from the UI, because the page reloaded before the database migrations finished ([#12506](https://github.com/coollabsio/coolify/issues/12506))

- The health endpoint `/api/health` answers 503 until all database migrations ran
