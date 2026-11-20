---
type: security
---
Hardened the input validation and value handling of preview deployments, server pages, and server transfer imports

- A preview Docker tag must be a valid image tag, as in the API, and a preview pull request URL must use http or https
