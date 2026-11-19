---
type: fixed
---
Databases that stopped and stayed down after a `Restart` when the image could not be pulled ([#12567](https://github.com/coollabsio/coolify/issues/12567))

- Coolify stops the database only after the image pull. If the pull fails, the restart fails and the database keeps running
