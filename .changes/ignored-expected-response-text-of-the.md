---
type: fixed
---
Ignored `Expected response text` of the HTTP healthcheck

- The response body must contain the text. A `HEAD` healthcheck does not check it
- The upgrade clears the texts that applications saved before, because the healthcheck did not check them. Set the text again to use the check
