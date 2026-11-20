---
type: fixed
---
Ignored access grants of members for tags, backup downloads, debug logs, secret values in the API, the dev view, and the configuration diff, team image scan settings and ignores, and the canvas pages

- Members with write access on a resource or on the whole team can now do these actions, the same as on the other pages
- The API shows the secrets of an application, service, database, or variable to a token with `read:sensitive` when the access grants of its member include `Read secrets` on that resource. Team credentials and server secrets stay hidden from members
