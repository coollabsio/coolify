---
type: fixed
---
Replaced `COOLIFY_RESOURCE_UUID` with an empty value or the network name when a Compose file referenced it, for example in a network `name:` ([#6401](https://github.com/coollabsio/coolify/issues/6401))

- A reference to a variable that Coolify sets no longer creates a resource variable, and Docker Compose reads `COOLIFY_RESOURCE_UUID` from the `.env` file. Delete a `COOLIFY_RESOURCE_UUID` variable that an older version created on the `Environment Variables` page, then redeploy
