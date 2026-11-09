---
type: fixed
commit: c3a21eceeb
---
Domains showing `Not checked` when an existing Cloudflare DNS record of a new domain has a different IP

- Coolify replaces the record when it created the record and no other resource uses it
- Otherwise it opens the `Configure DNS` dialog, where you can replace the record, and the domain shows the conflict
