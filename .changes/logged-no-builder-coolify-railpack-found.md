---
type: fixed
---
Logged `no builder "coolify-railpack" found` errors from the Docker cleanup on servers without Railpack deployments ([#12645](https://github.com/coollabsio/coolify/issues/12645))

- The cleanup prunes the Railpack build cache only when the builder exists
