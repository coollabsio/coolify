---
type: fixed
---
Unchecked `containers` names and `secret_manager.settings` keys in `coolify.json` plans ([#12688](https://github.com/coollabsio/coolify/issues/12688))

- The plan fails on unknown Compose services, on `containers` of a resource that is not Docker Compose, and on unknown or missing provider settings, as the API does. For a Compose application, it warns about names that the loaded Compose file does not have
