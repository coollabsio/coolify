---
type: fixed
---
Failed image and server package scans on servers with a slow disk, because the vulnerability database check stopped after 60 seconds ([#12512](https://github.com/coollabsio/coolify/issues/12512))

- The database check uses the `Grype time limit` and `docker save` uses the `Syft time limit` (at least 10 minutes) on the `Vulnerabilities` page of the server. A timeout of the database check names the check and the setting
