---
type: fixed
---
Wrong function and site domains of the Appwrite template, because `_APP_DOMAIN` got the `/v1/realtime` path of the API ([#3718](https://github.com/coollabsio/coolify/issues/3718))

- `SERVICE_FQDN_APPWRITE` is now the host of the console. Existing Appwrite services keep their compose: move `appwrite-console` below `appwrite-realtime` in the compose, or set `_APP_DOMAIN`, `_APP_DOMAIN_FUNCTIONS`, and `_APP_DOMAIN_SITES`
