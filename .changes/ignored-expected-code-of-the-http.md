---
type: fixed
---
Ignored `Expected code` of the HTTP healthcheck, so an endpoint that returns 401 or another 4xx/5xx code never became healthy ([#2218](https://github.com/coollabsio/coolify/issues/2218))

- A 2xx or 3xx response always passes. A 4xx or 5xx `Expected code` also passes that response
