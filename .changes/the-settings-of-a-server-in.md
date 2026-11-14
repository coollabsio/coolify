---
type: deprecated
---
The `is_logdrain_*` and `logdrain_*` fields of the server `settings` in the API, in favor of the new `logdrain_type` field

- The deprecated fields are read-only and still show the current values
- The `/servers/{uuid}/log-drains` endpoints keep their old fields
