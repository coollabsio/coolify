---
type: fixed
commit: 0b45d8dd6c
---
Deployments that stopped on a broken `.env` file, because multi-line JSON values saved before exact escaping had no quotes ([#8279](https://github.com/coollabsio/coolify/issues/8279))

- These values use exact escaping, which gives the container the same value as before beta.463
