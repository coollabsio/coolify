---
type: fixed
commit: a487d7c9d3
---
LiteLLM service template with a master key that did not start with `sk-` ([#10521](https://github.com/coollabsio/coolify/issues/10521))

- The admin API key is `sk-` followed by the value of `SERVICE_PASSWORD_MASTERKEY`
- A separate `LITELLM_SALT_KEY` keeps stored model credentials readable when the master key changes
- `STORE_MODEL_IN_DB` is `True` by default, so you can add models in the LiteLLM UI
